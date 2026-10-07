# laravel-offsite-backup

A Deployer recipe and hardened preset for [spatie/laravel-backup](https://github.com/spatie/laravel-backup), with pre-flight checks and off-server restore drills.

This is **not** a backup engine. spatie/laravel-backup takes the backups, cleans them up and monitors them. This package adds what we kept rebuilding around it across production apps:

- **`offsite:install`** publishes a hardened `config/backup.php`, derived from spatie's own config file, plus disk presets for Backblaze B2, Cloudflare R2, Amazon S3 and Wasabi.
- **Scheduling** of `backup:clean`, `backup:run` (with heartbeat pings), `backup:monitor`, and a scheduler heartbeat.
- **`offsite:doctor`** runs thirteen pre-flight checks that catch the failures that otherwise surface weeks later.
- **`offsite:verify`** is a restore drill you run *off* the server. It downloads a backup, decrypts it, restores the dumps into throwaway databases (a `postgres:<version>` container when you have Docker) and checks them.
- **`offsite:mirror`** copies another disk, such as an R2 media bucket, to the backup disk under `<name>-media/`, next to the backup folder rather than inside it. It never deletes.
- **`offsite-manifest.json`** in every archive records what was backed up, what was skipped and why.
- **`recipe/offsite-backup.php`** is a Deployer recipe. It writes `.env` settings from 1Password (one approval per run), Doppler, files or env vars, installs the scheduler without doubling it, and runs the commands remotely.

## Why

Every item below happened in production, on apps that "had backups":

- **No scheduler.** `backup:run` was scheduled, but nothing ran `schedule:run`. On another box, `/etc/cron.d` already ran it and a second crontab line would have run every task twice.
- **Unreadable files.** The scheduler ran as `www-data` from a systemd timer and couldn't read `.env` or parts of `storage/`. spatie's Finder stops at the first unreadable directory, *including directories you excluded*.
- **libzip without AES.** Many PHP builds can't do AES. With `'encryption' => 'default'` that is easy to miss until the first backup.
- **Size-cap pruning.** spatie's default `delete_oldest_backups_when_using_more_megabytes_than => 5000` quietly deletes the monthly backups the retention promises to keep.
- **Symlinks.** Under Deployer, `base_path()` is `releases/N` and `storage/` is a symlink. With `follow_links => false`, including `base_path()` skips all of storage.
- **B2 checksums.** Newer AWS SDKs send CRC checksums by default, which B2 (and other non-AWS endpoints) reject. The disk needs `request_checksum_calculation` and `response_checksum_validation` set to `when_required`.
- **Object Lock.** Locked objects can't be deleted, so a write probe stays until its retention ends. A key without delete rights also makes `backup:clean` fail.
- **pg_dump newer than the server.** A pg_dump 17 dump of a PostgreSQL 16 server contains `SET transaction_timeout`, which a 16 server rejects. Restore drills need a client and server at least as new as the pg_dump.
- Also: `DB_CONNECTION` was SQLite locally and PostgreSQL in production, and missing from another `.env`, so spatie dumped `mysql`; `pg_dump` was older than the server; `MAIL_FROM_ADDRESS` was still `hello@{{DOMAIN}}`, which spatie rejects before every run; `.env` changed after `config:cache`; a staging host sharing the crontab would have uploaded under the production name; 1Password asked for approval once per secret, and Deployer's prompts got dismissed.

## Requirements

- PHP 8.3+ with `ext-zip`. AES-256 needs libzip built with a crypto backend; `offsite:doctor` checks it.
- Laravel 12 or 13
- spatie/laravel-backup ^10
- `league/flysystem-aws-s3-v3` for the S3-compatible disks
- The dump tools for your databases (`sqlite3`, `pg_dump`, `mysqldump`/`mariadb-dump`) on the server, and the matching clients (`sqlite3`, `psql`, `mysql`) wherever you run `offsite:verify`. For PostgreSQL, Docker can stand in for `psql` and the scratch database.
- Deployer 8 for the recipe (optional; it also runs on 7.5)

## Quick start

```bash
composer require jothamlec/laravel-offsite-backup league/flysystem-aws-s3-v3

php artisan offsite:install --disk=b2          # prints config/backup.php and the b2 disk; publishes config/offsite-backup.php
php artisan offsite:install --write --disk=b2  # writes config/backup.php (refuses to overwrite without --force)
```

Paste the printed disk into `config/filesystems.php`, then set at least:

```dotenv
OFFSITE_BACKUP_NAME=my-app              # the folder in the bucket
OFFSITE_BACKUP_DISK=b2
OFFSITE_BACKUP_CONNECTIONS=pgsql        # explicit; never inferred from DB_CONNECTION
BACKUP_ARCHIVE_PASSWORD=...             # one per app
OFFSITE_BACKUP_HEARTBEAT_URL=https://status.example.com/api/push/xxxx
B2_ACCESS_KEY_ID=...
B2_SECRET_ACCESS_KEY=...
B2_BUCKET=...
B2_REGION=us-west-004
B2_ENDPOINT=https://s3.us-west-004.backblazeb2.com
```

Then:

```bash
php artisan offsite:doctor            # on the server
php artisan backup:run                # first backup
php artisan offsite:verify            # on your laptop, in CI, or on a dedicated box; never production
```

## What the hardened `config/backup.php` changes

`offsite:install` reads the `config/backup.php` shipped with your installed spatie version and rewrites these keys. Every rewrite must match exactly. If spatie changes the file's shape, the command fails instead of writing a half-hardened config.

| Key | spatie default | Hardened |
| --- | --- | --- |
| `encryption` | `'default'` | `'aes256'` |
| `verify_backup` / `tries` / `retry_delay` | `false` / `1` / `0` | `true` / `3` / `60` |
| `database_dump_compressor` | `null` | `GzipCompressor::class` |
| `databases` | `env('DB_CONNECTION')` | `offsite-backup.connections` (explicit) |
| `include` / `exclude` / `relative_path` | `base_path()` / vendor, node_modules / `null` | from `offsite-backup` (Deployer's `shared/` by default) |
| `temporary_directory` | `storage/app/backup-temp` | `offsite-backup.temporary_directory` (same default, and excluded) |
| Success notifications | `['mail']` | `[]` (failures still mail `BACKUP_NOTIFY_EMAIL`) |
| Retention | 7 / 16 / 8 / 4 / 2 | 7 all / 23 daily / 0 weekly / 12 monthly / 0 yearly |
| Size cap | 5000 MB | `null` |
| `name`, disks, monitor | `APP_NAME`, `local`, 5000 MB | `offsite-backup.name`, `.disk`, `.monitor.max_storage_mb` |

`config/backup.php` is evaluated before `config/offsite-backup.php`, so it reads the latter through `Preset::load(__DIR__.'/offsite-backup.php')` rather than `config()`. The service provider only merges defaults into `offsite-backup`; it never rewrites spatie's config at runtime, and your published files always win.

## Configuration: `config/offsite-backup.php`

| Key | Default | |
| --- | --- | --- |
| `name` | `OFFSITE_BACKUP_NAME`, else slug of `APP_NAME` | The folder in the bucket |
| `disk` | `OFFSITE_BACKUP_DISK`, `b2` | |
| `shared_path` | `null` | `null`: Deployer's `{{deploy_path}}/shared` when `base_path()` is `releases/N`, else `base_path()` |
| `include` | `['.']` | Relative to `shared_path`, or absolute |
| `exclude` | `storage/framework`, `storage/logs`, `storage/app/backup-temp` | Plus `vendor` and `node_modules` |
| `connections` | `OFFSITE_BACKUP_CONNECTIONS` (comma-separated) | Database connections to dump |
| `schedule.enabled` / `time` / `window` | `true` / `'auto'` / `01:00–05:00` | `'auto'` hashes the name into the window (5-minute slots), staggering many apps |
| `schedule.timezone` / `environments` / `clean` | `UTC` / `['production']` / `true` | |
| `schedule.monitor_time` | `OFFSITE_BACKUP_MONITOR_TIME`, `'auto'` | `'HH:MM'` for `backup:monitor`; `'auto'` is an hour after `backup:clean` |
| `heartbeat.url` / `format` | none / `kuma` | `kuma`, `healthchecks` or `plain` |
| `mirror.enabled` / `source` / `prefix` / `time` / `destination` | `false` / none / `''` / `'auto'` / `<name>-media` | See [offsite:mirror](#offsitemirror). `OFFSITE_MIRROR_ENABLED`, `OFFSITE_MIRROR_SOURCE`, `OFFSITE_MIRROR_PREFIX`, `OFFSITE_MIRROR_TIME`, `OFFSITE_MIRROR_DESTINATION` |
| `monitor.max_storage_mb` | `20000` | spatie's `MaximumStorageInMegabytes` |
| `manifest` | `true` | Add `offsite-manifest.json` to archives |
| `doctor.checks` | the 13 checks below | Add or remove classes implementing `Doctor\Check`. A config published before 0.2.0 lists 11: add `DatabasesAreExplicit` and `MailAddressesAreValid` |
| `verify.*` | | See [offsite:verify](#offsiteverify) |

For a slow PostgreSQL dump, set a timeout on the connection in `config/database.php`, which spatie passes to `pg_dump`: `'dump' => ['timeout' => 900]`. Set `dump_binary_path` there too when the right `pg_dump` isn't first on the scheduler's `PATH`.

## Disk presets

`php artisan offsite:install --disk=b2|r2|s3|wasabi` prints a disk for `config/filesystems.php` and the `.env` keys it reads. Every preset sets `'throw' => true`. Non-AWS endpoints (B2, R2, Wasabi) also set `request_checksum_calculation` and `response_checksum_validation` to `when_required`, and `offsite:doctor` fails if a non-AWS S3 disk lacks them. The S3 preset is named `s3-offsite` so it doesn't collide with Laravel's `s3` disk.

## Security

- **One key per app, limited to its folder.** On B2 (CLI v4): `b2 key create --bucket <bucket> --name-prefix <name>/ <key-name> listBuckets,listFiles,readFiles,writeFiles` (add `deleteFiles` only without Object Lock; never grant `bypassGovernance` or `writeFileRetentions` to an app key). On S3, use an IAM policy scoped to `arn:aws:s3:::<bucket>/<name>/*` and `s3:prefix` `<name>/`. R2 API tokens with Object Read & Write can be limited to specific buckets but not to prefixes: use one bucket per app. A compromised server should only be able to touch its own backups. **With `offsite:mirror`**, the key also needs the mirror's prefix (`<name>-media/` by default): on B2 create it with `--name-prefix <name>` (no trailing slash, which covers `<name>/` and `<name>-media/`; avoid app names that are prefixes of one another), on S3 add `arn:aws:s3:::<bucket>/<name>-media/*` and the `s3:prefix` `<name>-media/`. A B2 key made with `--name-prefix <name>/` can't write `<name>-media/`: replace it (B2 key prefixes can't be changed).
- **Object Lock, without delete permission.** With Object Lock on the bucket, give the app's key no delete rights, so a compromised server can't destroy its history. Then set `OFFSITE_BACKUP_CLEAN=false`, because `backup:clean` can't delete, and let the bucket's lifecycle rules expire old backups. Since nothing gets hidden or deleted, a B2 rule needs `daysFromUploadingToHiding` (at least the lock period) plus `daysFromHidingToDeleting`. Lifecycle rules skip versions that are still locked. Prefer compliance mode: in governance mode, any key with `bypassGovernance` can remove the lock.
- **One archive password per app**, stored in your password manager, not only on the server. Without it the backups are useless, and if one leaks only one app's archives are exposed.
- **Never commit secrets.** The Deployer recipe resolves them on your machine (`op read`, `doppler secrets get`, local env, files) and writes them straight into `shared/.env`.
- **`.env` is inside the archive**: `APP_KEY`, database passwords, API keys and the bucket key itself. That is what makes a restore complete, and why archive encryption is not optional. `offsite:doctor` fails without AES-256 and a password.
- `offsite:verify --keep` leaves a decrypted copy, including `.env`, in a temp dir. Delete it when done.

## Scheduling and heartbeats

When `schedule.enabled` is on, the service provider registers these on Laravel's schedule, in `schedule.timezone` and only in `schedule.environments`:

| Command | When | |
| --- | --- | --- |
| `backup:clean` | T | `withoutOverlapping`; skipped with `schedule.clean = false` |
| `backup:run` | T + 5 min | `withoutOverlapping`, pings the heartbeat on success and failure |
| `backup:monitor` | T + 60 min, or `schedule.monitor_time` (`OFFSITE_BACKUP_MONITOR_TIME`) | `withoutOverlapping` |
| `offsite:mirror` | T + 20 min, or `mirror.time` | Only with `mirror.enabled` and a `mirror.source`; `withoutOverlapping`, pings the heartbeat's failure URL when it fails |
| `offsite:heartbeat-tick` | every minute | Records the last scheduler run and its OS user, for `offsite:doctor` |

Heartbeat formats: `kuma` (Uptime Kuma push URLs; `?status=up&msg=OK` and `?status=down&msg=...`, replacing the push URL's own query), `healthchecks` (the URL, and `/fail`), `plain` (the URL on success only). Point a push monitor at it expecting a ping every 24 hours.

You still need *one* `schedule:run` entry per app; see `dep offsite:scheduler`.

## `offsite:doctor`

```bash
php artisan offsite:doctor                 # table of PASS / WARN / FAIL with fixes; exit 1 on any FAIL
php artisan offsite:doctor --json
php artisan offsite:doctor --write-probe   # also writes and deletes a tiny object (Object Lock keeps it)
```

| # | Check | Fails / warns when |
| --- | --- | --- |
| 1 | Scheduler is running | no `offsite:heartbeat-tick` within 2 minutes (WARN if it never ran, or if this `APP_ENV` schedules nothing) |
| 2 | OS user | always reports the current user; WARN when the scheduler's recorded user differs (run doctor as that user) |
| 3 | Include paths readable | every unreadable file or directory (up to 20), with owner and mode. It descends into excluded directories and fails on unreadable ones: spatie's Finder opens them before filtering, so excluding a directory doesn't help |
| 4 | Archive encryption | libzip lacks AES-256 (FAIL where backups are scheduled; WARN where `APP_ENV` isn't in `schedule.environments`, i.e. a machine that only verifies, saying whether libzip or 7-Zip can decrypt there); encryption is `none` (FAIL) or `default` (WARN); no password. The server that runs `backup:run` must support AES-256 |
| 5 | Size cap and monitor | the cap is below estimated archive size x backups kept (FAIL); the monitor's limit is below it, or no monitor watches this backup (WARN) |
| 6 | Paths and symlinks | include outside `base_path()` without `relative_path`; a symlinked include path; symlinked directories that are skipped; `temporary_directory` inside an include and not excluded |
| 7 | Disk reachable | a read-only, top-level listing of `<name>/` fails (it counts the `*.zip` archives); non-AWS endpoint without `when_required`; `throw` off; non-zip objects under `<name>/`, found by a recursive listing capped at 1,000 objects (WARN: they make spatie's listings slow, see [offsite:mirror](#offsitemirror)) |
| 8 | Dump binaries | `sqlite3` / `pg_dump` / `mysqldump` / `mariadb-dump` missing (honours `dump_binary_path`); `pg_dump` major older than `SHOW server_version_num` |
| 9 | Config cache | `.env` is newer than the cached config (WARN) |
| 10 | Schedule timezone | no explicit timezone (WARN); shows the resolved times |
| 11 | Heartbeat | no heartbeat URL (WARN) |
| 12 | Databases explicit | `config/backup.php` takes `backup.source.databases` from `DB_CONNECTION` (FAIL; it says when `DB_CONNECTION` isn't set and the default was used); a listed connection doesn't exist (FAIL); it differs from `offsite-backup.connections` (WARN) |
| 13 | Mail addresses | the sender (`MAIL_FROM_ADDRESS`) or a recipient isn't a valid address (FAIL: spatie rejects its whole config, mail notifications on or off); a placeholder such as `your@example.com` (WARN) |

Each check is a class implementing `Jothamlec\OffsiteBackup\Doctor\Check`, resolved from the container. Reorder, remove or add checks in `offsite-backup.doctor.checks`.

## `offsite:verify`

Run it **anywhere except production**: a laptop, a CI job or a dedicated box with the same app code and the backup credentials. It never writes to the bucket.

```bash
php artisan offsite:verify                       # the newest backup
php artisan offsite:verify --backup=2026-10-07-19-05-00.zip
php artisan offsite:verify --name=other-app --disk=b2   # another app's backups
php artisan offsite:verify --json                # machine-readable report
php artisan offsite:verify --keep -v             # keep the extracted files; list row counts per table
```

What it does:

1. Lists the `*.zip` files at the top of `<name>/` on the disk through Flysystem (not recursively; a listing error is reported, not mistaken for an empty bucket) and picks the newest, or `--backup`. The newest must be younger than `verify.maximum_age_hours` (26).
2. Streams it to a temp dir and opens it with `ZipArchive` and `BACKUP_ARCHIVE_PASSWORD`. When this PHP's libzip can't decrypt AES (common with Nix and Homebrew PHP), it extracts with 7-Zip (`7zz`, `7z` or `7za` on `PATH`), passing the password on stdin rather than the command line (in a new session via `setsid` or `perl`, so 7-Zip doesn't prompt on the terminal; only if neither exists does it fall back to `-p`). Without 7-Zip it fails with a hint to install it. It also fails when the password is missing, or when extraction fails or comes out empty (wrong password). An unencrypted archive is a WARN.
3. Reads `offsite-manifest.json`.
4. File checks: `verify.expected_paths` (default `.env`) and the relative include roots are present, and there are at least `verify.minimum_files` files.
5. For each dump in `db-dumps/`, it rejects truncated dumps (no end-of-dump marker), then restores:
   - **SQLite** into a temp file with the `sqlite3` CLI, then `PRAGMA integrity_check` and row counts per table.
   - **PostgreSQL / MySQL / MariaDB** into `verify.scratch_connection`, a throwaway database whose tables are dropped first, via `psql` / `mysql`. It **refuses** when the scratch connection's host, port and database match any backed-up connection, whether from this app's config or from the archive's manifest.
   - **PostgreSQL without a scratch connection** goes into a throwaway Docker container (`verify.docker`, on by default) running `postgres:<major>`, the major version of the pg_dump that made the dump (from its `Dumped by pg_dump version N` header). The container listens on 127.0.0.1 only and is removed afterwards. It needs Docker and `pdo_pgsql`.
   - **PostgreSQL versions.** The report records the dump's pg_dump and server versions. A restore uses a `psql` at least as new as the pg_dump: the oldest such client on `PATH`, in `dump_binary_path` or in the usual versioned locations (`/usr/lib/postgresql/N/bin`, Homebrew `postgresql@N`), else `psql` from the `postgres:<major>` image. When the scratch server is older than the pg_dump, the settings it doesn't know (`transaction_timeout` in a pg_dump 17 dump) are ignored and the report says so.
   - Restore errors about missing roles and ownership are never counted (the dump's owners don't exist in a throwaway database); they appear as info in the report, with counts. `verify.ignore_restore_errors` adds patterns. Other errors fail the restore.
6. Runs the health checks on every restored database. `MinimumTables` (`verify.minimum_tables`) always runs; add your own:

   ```php
   'health_checks' => [
       [\Jothamlec\OffsiteBackup\Verify\HealthChecks\TableHasRows::class, ['table' => 'users', 'min' => 1]],
       [\Jothamlec\OffsiteBackup\Verify\HealthChecks\NewestRowWithin::class, ['table' => 'orders', 'column' => 'created_at', 'hours' => 48, 'dump' => 'shop']],
   ],
   ```

   Implement `Verify\HealthChecks\HealthCheck` for your own checks. The optional `dump` option limits a check to dump files whose name contains it.
7. Pings `verify.heartbeat.url` (`OFFSITE_VERIFY_HEARTBEAT_URL`) with the result, unless `--no-ping`, and exits non-zero on failure.

A weekly CI job running `offsite:verify --json` with a separate heartbeat is the cheapest proof that your backups restore.

**Statamic apps:** run it with `CACHE_STORE=array`: `CACHE_STORE=array php artisan offsite:verify`, or for `dep offsite:verify`, `set('offsite_verify_command', 'CACHE_STORE=array php artisan offsite:verify')` (not `offsite_env_extra`, which is also written to the server's `.env`). Statamic uses the configured cache store after the command has finished, so a local project whose cache points at an unreachable Redis or database errors after a successful drill.

## `offsite:mirror`

Copies a Flysystem disk, typically a media bucket on R2 or S3, to the backup disk under `<name>-media/<path>` (`mirror.destination`, `OFFSITE_MIRROR_DESTINATION`):

```bash
php artisan offsite:mirror                  # mirror.source, or:
php artisan offsite:mirror --source=media --prefix=uploads/
php artisan offsite:mirror --dry-run -v     # list what would be copied
```

- Copies only objects missing from the backup or whose size differs, streaming each one (nothing is loaded into memory).
- **Never deletes.** An object removed from the source stays in the backup; an Object Lock bucket would refuse the delete anyway.
- The backup keeps each object's full source path, so `--prefix` limits what's copied without changing the layout: `media/a.png` on the source is always `<name>-media/media/a.png`.
- Prints `Copied`, `Already backed up` and `Failed` counts, carries on past a failed object, and exits non-zero if any failed.
- With `mirror.enabled` (`OFFSITE_MIRROR_ENABLED=true`) and `mirror.source` (`OFFSITE_MIRROR_SOURCE`), it is scheduled daily 15 minutes after `backup:run` (or at `OFFSITE_MIRROR_TIME`), with the backup schedule's timezone and environments. A failure pings the heartbeat's failure URL; a success pings nothing, so it can't hide a failed `backup:run`.
- The backup disk's key needs list, read and write rights on the destination prefix (see [Security](#security)). The source disk's key needs list and read rights only.

The mirror isn't encrypted: objects are copied as they are, like the source bucket holds them.

**Why not inside `<name>/`.** spatie's `backup:list`, `backup:clean` and `backup:monitor` list `<name>/` *recursively*, and for every object without a `.zip` extension ask the disk for its MIME type (`BackupCollection::createFromFiles` → `File::isZipFile`), which on S3 is one HEAD request per object. With 3,546 mirrored objects under `<name>/media/`, each of those commands took about 25 minutes. The destination therefore defaults to a sibling prefix, the package's own listings (`offsite:verify`, the doctor's disk check) read only the top-level `*.zip` files, and `offsite:doctor` warns when anything else sits under `<name>/`. If you set `OFFSITE_MIRROR_DESTINATION` inside `<name>/` anyway, `offsite:mirror` warns on every run.

### Moving a v0.2.0 or v0.2.1 mirror out of `<name>/media/`

v0.2.0 and v0.2.1 mirrored into `<name>/media/`. After upgrading, **relocate before the next mirror run**: otherwise the mirror re-copies everything from the source into `<name>-media/` while the old copies stay under `<name>/`.

1. Make sure the backup disk's key may write `<name>-media/` (see [Security](#security); on B2 a key limited to `<name>/` must be replaced by one limited to `<name>`). Deploy the new key with `dep offsite:env`.
2. Preview, then move:

   ```bash
   php artisan offsite:mirror --relocate-from=<name>/media --dry-run
   php artisan offsite:mirror --relocate-from=<name>/media
   ```

   For each object under `<name>/media/` it does a server-side copy to `<name>-media/` (Flysystem `copy()`, an S3 CopyObject: B2's S3 API supports it without download or egress), checks the copy's size against the original, and only then deletes the old key. An object already at the destination with the same size is not copied again; its old key is just deleted. It prints `Moved`, `Already at the destination` and `Failed` counts and exits non-zero if any failed, keeping the old key of every failed object. It is idempotent and resumable: run it again after an interruption or a failure; when nothing is left it says so. It refuses `--relocate-from=<name>` (that would move the backups) and prefixes that overlap the destination.
3. **Object Lock:** on a B2 bucket with Object Lock, the delete (an S3 DeleteObject without a version id) only *hides* the old object: its locked version is kept until the retention and lifecycle rules remove it, but it disappears from listings, which is what makes spatie fast again. The key needs `deleteFiles` for that step only; if it lacks it, every delete fails, nothing is lost, and you can run the relocation with a temporary key that has it.
4. `php artisan offsite:doctor` should no longer warn about non-zip objects under `<name>/`, and `php artisan offsite:mirror` should report everything as already backed up.

To keep the old layout instead (not recommended), set `OFFSITE_MIRROR_DESTINATION=<name>/media`.

## Archive manifest

A listener on spatie's `BackupManifestWasCreated` event writes `offsite-manifest.json` next to spatie's `manifest.txt` and adds it to the file list. That event fires after the dumps and before the zip is built, so the manifest sits at the archive root and is **encrypted with everything else**. It records:

- the package and spatie versions, app name, backup name, environment, hostname and `created_at`
- the included roots and `relative_path`
- per connection: driver, host, port, database, server version and dump tool version
- skipped items with reasons: excluded paths, missing include paths, symlinked directories, and connections in `offsite-backup.connections` missing from `backup.source.databases`

If writing it fails, the error is reported and the backup continues without it.

## Deployer

```php
// deploy.php
require 'recipe/laravel.php';
require 'contrib/crontab.php'; // optional: offsite:scheduler delegates to it when loaded
require 'vendor/jothamlec/laravel-offsite-backup/recipe/offsite-backup.php';

set('offsite_stages', ['production']);       // hosts by `stage` label (or alias); [] for all
set('offsite_name', 'my-app');               // default: slug of `application`
set('offsite_secrets', [
    'BACKUP_ARCHIVE_PASSWORD' => 'op://Ops/my-app-backup/archivePassword',
    'B2_ACCESS_KEY_ID' => 'op://Ops/my-app-backup/keyID',
    'B2_SECRET_ACCESS_KEY' => 'op://Ops/my-app-backup/applicationKey',
    'OFFSITE_BACKUP_HEARTBEAT_URL' => 'op://Ops/my-app-backup/heartbeat?', // trailing ? = optional
]);
set('offsite_env_extra', [                   // non-secret settings, written next to the secrets
    'B2_BUCKET' => 'my-backups',
    'B2_REGION' => 'us-west-004',
    'B2_ENDPOINT' => 'https://s3.us-west-004.backblazeb2.com',
    'OFFSITE_BACKUP_CONNECTIONS' => 'pgsql',
    'OFFSITE_BACKUP_TIME' => '02:30',         // optional; default 'auto'
]);
set('offsite_reader_user', 'www-data');      // for offsite:acl, when the scheduler isn't the deploy user
set('offsite_cron_umask', '002');            // optional: keep files the scheduler creates group-writable
```

| Setting | Default | |
| --- | --- | --- |
| `offsite_stages` | `['production']` | Hosts (by `stage` label, else alias) the tasks may run on; `[]` for all |
| `offsite_name` | slug of `application` | `OFFSITE_BACKUP_NAME`, the folder in the bucket |
| `offsite_secrets` | `[]` | ENV key => secret source (below) |
| `offsite_secrets_file` | `null` | A local `KEY=VALUE` file used instead of the sources for the keys it defines (and adding the others). Must not be readable by group or others (`chmod 600`) |
| `offsite_env_extra` | `[]` | Non-secret ENV key => value (strings, numbers, booleans as `true`/`false`, lists joined with commas) |
| `offsite_legacy_markers` | `[['# >>> off-site backup', '# <<< off-site backup']]` | `[start, end]` line prefixes of hand-rolled blocks that `offsite:env` removes from `shared/.env` |
| `offsite_reader_user` / `offsite_acl_paths` | `null` / `[]` | `offsite:acl`: `.env` is always included; list more paths under `shared/` (e.g. `['storage']`) explicitly |
| `offsite_cron_umask` / `offsite_cron_marker` | `null` / `# {{offsite_name}} scheduler ...` | `offsite:scheduler` |
| `offsite_verify_command` | `php artisan offsite:verify` | What `offsite:verify` runs locally |

Secret sources: `env:VAR` (local environment), `op://vault/item/field` (1Password), `doppler:NAME` (`doppler secrets get NAME --plain`), `file:/path` (trimmed), `literal:...`, a closure, or a plain string. Values may not contain single quotes or newlines (`.env` single quotes can't hold them); anything else, `$`, `"`, `\` and spaces included, is written as is.

**1Password: one approval per run.** Every `op://` source is read with a single `op inject`, its template piped on stdin, so the desktop app asks once per task instead of once per secret (separate `op read` calls each prompt, and prompts raised from Deployer are easily dismissed). If an optional (`?`) reference fails, `op inject` runs once more without the optional ones. Use item IDs or names without spaces in references. Commands run through Symfony Process, not `runLocally()`, so no secret appears in Deployer's output or error messages.

| Task | Runs | |
| --- | --- | --- |
| `offsite:env` | server | Writes a marked block into `{{deploy_path}}/shared/.env` *in place* (`cat >`, so owner, mode and ACLs survive), replacing the previous block and removing `offsite_legacy_markers` blocks (it refuses to touch `.env` when a start marker has no end marker); writes `OFFSITE_BACKUP_SHARED_PATH`, `OFFSITE_BACKUP_NAME`, `offsite_env_extra` and the secrets; then `config:cache` if `current` exists |
| `offsite:acl` | server | Read access for `offsite_reader_user` on `.env` and `offsite_acl_paths` (default: none), adding or widening ACL entries only where that user can't read yet; never narrows a permission (below) |
| `offsite:scheduler` | server | Refuses when `/etc/cron.d/*`, a systemd unit or an unmarked crontab line already runs `schedule:run` for this deploy path (`--force` overrides). Otherwise adds the line through `contrib/crontab.php` (`crontab:sync`) when loaded, or as its own marked crontab line |
| `offsite:doctor` / `offsite:run` / `offsite:list` | server | `artisan offsite:doctor` / `backup:run` / `backup:list` in `current` |
| `offsite:verify` | locally | `php artisan offsite:verify --name=<offsite_name>` in your local project, with `OFFSITE_BACKUP_NAME`, `offsite_env_extra` and the resolved secrets in its environment (never on a command line), so your local `.env` needs no production keys. Environment variables win over `.env`; it refuses to run when the local config is cached, since a cached config ignores them |

`offsite:env` and `offsite:acl` resolve `{{deploy_path}}/shared` once on the server (`cd {{deploy_path}}/shared && pwd -P`, unquoted, so a leading `~` expands) and use that absolute path afterwards. If the merge into `.env` fails, `offsite:env` deletes the uploaded block (it holds the secrets).

**`offsite:acl` never narrows permissions.** `setfacl -m u:www-data:rX` *replaces* www-data's entry, so an existing `u:www-data:rwx` on `storage` or a media folder would become `r-x`, and because a named-user entry takes precedence over group entries, a www-data that wrote through its group (`deploy:www-data` with mode 664) would lose write access too. 0.2.1 and earlier did exactly that, recursively, on `storage` by default. Since 0.2.2 the task reads every path's ACL first (`getfacl`, plus `id -nG <user>` for the user's groups) and works out what the user can do now through any route (owner, named entry, groups, other, mask). Paths it can already read (and search, for directories) are left alone. Elsewhere it sets the user's entry to the *union* of its current entry, its current access and `r` (`rx` on directories). Default ACLs on directories (what new files get) are treated the same way: they are left alone when they already give `rx`, otherwise set to the union of the existing default entry, the access the user has on the directory and `rx`. Paths the user owns but can't read are reported, not changed (an ACL can't override owner bits), and symlinks are skipped. Setting an entry recalculates the ACL mask, which can widen other named entries' effective rights but never narrows them. The task needs `getfacl` and `setfacl` (the `acl` package) and prints how many paths were already readable and how many entries it added.

If an earlier version already ran on your server, check `getfacl -p shared/storage` (and a few files under it): an entry like `user:www-data:r-x` where www-data used to write must be fixed by hand, for example `setfacl -R -m u:www-data:rwX shared/storage` plus `find shared/storage -type d -exec setfacl -d -m u:www-data:rwX {} +`.

**Passing settings with `-o`.** Deployer's `-o key=value` cuts the value at the next `=`, so `dep offsite:env -o offsite_name=a=b` sets `a`, and a base64 secret with `=` padding is silently truncated (shell quoting doesn't help: Deployer splits the option itself). Put secrets in `offsite_secrets_file` (or `offsite_secrets` sources) instead of `-o`, and keep `-o` for plain values without `=`.

Every task refuses hosts outside `offsite_stages`. The recipe's logic lives in plain, tested classes under `src/Deployer/`; the recipe itself is thin glue.

## Docker and Dokploy

On a container platform such as Dokploy there is no `shared/.env` and no Deployer: the platform injects the environment, and the image is rebuilt on every deploy.

- **Environment.** Set the same keys in the platform's environment settings: `OFFSITE_BACKUP_NAME`, `OFFSITE_BACKUP_CONNECTIONS`, `BACKUP_ARCHIVE_PASSWORD`, the disk's keys (`B2_ACCESS_KEY_ID`, `B2_SECRET_ACCESS_KEY`, `B2_BUCKET`, `B2_REGION`, `B2_ENDPOINT`), `OFFSITE_BACKUP_HEARTBEAT_URL`, plus `OFFSITE_MIRROR_*` for a media mirror. Leave `OFFSITE_BACKUP_SHARED_PATH` unset unless you include a mounted volume: with no Deployer `releases/` layout, `shared_path` falls back to `base_path()`.
- **What to include.** Inside a container, `base_path()` is the image: rebuilt code, not data. Point `offsite-backup.include` at what isn't in the image (`storage/app`, a mounted volume, `storage/statamic`-style content you keep) and set `OFFSITE_BACKUP_SHARED_PATH` to its root, or list absolute paths. `.env` doesn't exist, so drop it from `verify.expected_paths`.
- **Scheduler.** Run one scheduler container (or process) per app with `php artisan schedule:work`, from the same image and environment as the web container. Don't also add a `schedule:run` cron: that runs everything twice. `offsite:heartbeat-tick` lets `offsite:doctor` see the scheduler (`docker exec <scheduler> php artisan offsite:doctor`). Staging often shares the image with `APP_ENV=production`: set `OFFSITE_BACKUP_SCHEDULE=false` there, or give staging its own environment name.
- **Dump tools in the image.** Install the database client in the image, at least as new as the server: for PostgreSQL 16 or 17, Debian trixie's `postgresql-client` (17) dumps both. In a Dockerfile: `RUN apt-get update && apt-get install -y --no-install-recommends postgresql-client && rm -rf /var/lib/apt/lists/*`. `offsite:doctor` checks the version against the server.
- **Restore drills** still run elsewhere: `php artisan offsite:verify` on a laptop or in CI with the same keys in the environment. With Docker there, PostgreSQL dumps restore into a throwaway `postgres:<pg_dump major>` container.
- The Deployer tasks don't apply; run the commands with `docker exec` (or the platform's terminal).

## Migrating from a hand-rolled spatie setup

For apps with their own `deploy/backup.php`, a published spatie `config/backup.php`, a schedule block in `routes/console.php` and a `# >>> off-site backup` block in `shared/.env`:

1. `composer require jothamlec/laravel-offsite-backup` (keep `league/flysystem-aws-s3-v3`).
2. **Schedule.** Delete the `backup:clean` / `backup:run` / `backup:monitor` block from `routes/console.php`. The package schedules them (plus the heartbeat tick); keeping both runs every backup twice. To keep the old time, set `OFFSITE_BACKUP_TIME` to the old `backup:clean` time (in `OFFSITE_BACKUP_TIMEZONE`, UTC by default).
3. **Recipe.** Replace `deploy/backup.php` and its `require` in `deploy.php` with the package recipe and the settings below. The task names change: `backup:env` becomes `offsite:env`, `scheduler:install` becomes `offsite:scheduler` (it finds the old crontab line by its path and refuses to add a second one: remove the old line, or keep it and skip this task), `backup:run` / `backup:list` / `backup:verify` become `offsite:run` / `offsite:list` / `offsite:verify`. Delete `deploy/backup-verify`.
4. **Config.** Either keep your `config/backup.php` and make its `name`, `disks`, `databases`, include/exclude and password match the table below, or regenerate it: `php artisan offsite:install --write --force` (it also publishes `config/offsite-backup.php`). Regenerating keeps the values you customised in the old file: `backup.name`, the first `monitor_backups` entry's `name`, `notifications.mail.to` and the mail `from` address and name. A value counts as customised when its source text differs from both spatie's default and the hardened default; it is copied as written (an `env()` call stays an `env()` call) and the command lists what it kept. Everything else, the hardening included, is regenerated; multi-line values aren't carried over. Regenerating is recommended: `offsite:doctor` fails a `databases` entry taken from `DB_CONNECTION`. The hardened retention keeps 7 days of every backup, 23 more daily and 12 monthly.
5. **Disk.** Replace the `b2` disk in `config/filesystems.php` with `php artisan offsite:install --disk=b2`'s version, which reads `B2_ACCESS_KEY_ID` and `B2_SECRET_ACCESS_KEY`. (Keeping your disk is fine too; then write its key names with `offsite:env` instead.)
6. **Environment.** `dep offsite:env production` removes the old marked block and writes the new one. The keys:

   | Hand-rolled key | Package key | |
   | --- | --- | --- |
   | `BACKUP_NAME` | `OFFSITE_BACKUP_NAME` | `offsite_name`; keep the old value so the backups stay in the same folder |
   | `BACKUP_SHARED_PATH` | `OFFSITE_BACKUP_SHARED_PATH` | written by `offsite:env` |
   | `BACKUP_ARCHIVE_PASSWORD` | `BACKUP_ARCHIVE_PASSWORD` | unchanged (spatie's own key) |
   | `BACKUP_HEARTBEAT_URL` | `OFFSITE_BACKUP_HEARTBEAT_URL` | Uptime Kuma push URL; format `kuma` by default |
   | `B2_KEY_ID` | `B2_ACCESS_KEY_ID` | read by the `b2` preset disk |
   | `B2_APPLICATION_KEY` | `B2_SECRET_ACCESS_KEY` | read by the `b2` preset disk |
   | `B2_REGION`, `B2_BUCKET` | unchanged | the preset's defaults are `us-west-004` and none: set both |
   | `B2_ENDPOINT` | unchanged | with `https://` |
   | (`DB_CONNECTION`) | `OFFSITE_BACKUP_CONNECTIONS` | new and required: the connections to dump, e.g. `pgsql` |
   | `BACKUP_NOTIFY_EMAIL` | unchanged | failure mail; `MAIL_FROM_ADDRESS` must be a real address |

7. **Check.** `dep offsite:doctor production`, then `dep offsite:run production` and `dep offsite:verify production`.
8. **Tests.** Remove tests that asserted the hand-rolled config or schedule (for example a `BackupConfigTest`); the package tests its own.
9. **Old archives keep the old password.** If the app moves to its own archive password, the archives made before the switch still open only with the previous one, until `backup:clean` rotates them out. Keep the old password until then; to check one, override it for that run: `BACKUP_ARCHIVE_PASSWORD="$(op read 'op://<vault>/<old item>/archivePassword')" php artisan offsite:verify --backup=<old archive>.zip`.

A minimal `deploy.php` for such an app:

```php
require 'recipe/laravel.php';
require 'vendor/jothamlec/laravel-offsite-backup/recipe/offsite-backup.php';

set('offsite_stages', ['production']);
set('offsite_name', 'my-app');                       // the old BACKUP_NAME
set('offsite_secrets', [                             // one `op inject`, one approval
    'BACKUP_ARCHIVE_PASSWORD' => 'op://Personal/<item>/archivePassword_my-app',
    'B2_ACCESS_KEY_ID' => 'op://Personal/<item>/keyID',
    'B2_SECRET_ACCESS_KEY' => 'op://Personal/<item>/applicationKey',
    'OFFSITE_BACKUP_HEARTBEAT_URL' => 'op://Personal/<item>/heartbeat_my-app?',
]);
set('offsite_env_extra', [
    'B2_BUCKET' => 'my-bucket',
    'B2_REGION' => 'us-east-005',
    'B2_ENDPOINT' => 'https://s3.us-east-005.backblazeb2.com',
    'OFFSITE_BACKUP_CONNECTIONS' => 'pgsql',
    'OFFSITE_BACKUP_TIME' => '19:00',                // the old backup:clean time, UTC
]);
```

## Restore runbook

1. **On a safe machine**, fetch and test the backup: `php artisan offsite:verify --keep` (or `--backup=` for an older one). Note the printed directory; `extracted/` holds `.env`, the shared files and `db-dumps/`.
2. **Provision** the server and deploy the code: `dep deploy production`. Don't run the app's scheduler yet.
3. **Files**: copy the extracted tree into `{{deploy_path}}/shared/`, for example `rsync -a extracted/storage/ server:/srv/app/shared/storage/`. Restore `.env` too, and keep its `APP_KEY`: encrypted columns, sessions and signed URLs depend on it. Check owners and ACLs (`dep offsite:acl`).
4. **Database**:
   - PostgreSQL: `gunzip -c db-dumps/postgresql-<db>.sql.gz | psql -h ... -U ... -d <db>`
   - MySQL / MariaDB: `gunzip -c db-dumps/mysql-<db>.sql.gz | mysql -h ... -u ... <db>`
   - SQLite: `gunzip -c db-dumps/sqlite-<conn>-database.sql.gz | sqlite3 shared/database/database.sqlite`
5. `php artisan migrate --force` if the code is newer than the backup, then `config:cache`, `queue:restart`.
6. Statamic: see below.
7. `dep offsite:scheduler production`, `dep offsite:doctor production`, then `dep offsite:run production` so the restored server has a fresh backup.
8. Delete the extracted copy.

## Statamic

- **Flat-file content.** Entries, globals, navigation and users are YAML/Markdown files. If editors change them on the server and git automation doesn't commit them, they must live in Deployer shared dirs, so they sit under `shared/` and get backed up. Assets in `public/assets` (or another shared disk) need the same.
- **Eloquent driver.** Content lives in the database, so add that connection to `OFFSITE_BACKUP_CONNECTIONS`.
- **Exclude the caches**: add `storage/statamic` (the Stache, Glide cache, search indexes) to `offsite-backup.exclude`. They are rebuilt.
- **`offsite:verify` locally:** run it with `CACHE_STORE=array`; see [offsite:verify](#offsiteverify).
- **After a restore:** `php please stache:refresh`, `php please search:update --all`, `php please glide:clear`, and `php please static:clear` when static caching is on.

## Testing

```bash
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan (Larastan) level 8
```

The tests run spatie's real `backup:run` against a sandboxed SQLite app and verify the archive. Each test's sandbox is a `offsite-backup-tests-*` directory in the system temp dir, deleted after the test even when it fails, at exit, and on SIGINT/SIGTERM; sandboxes older than six hours (from a killed run) are swept on the next run. The AES round-trip tests skip when the local libzip has no AES; CI reports which applies.

## Credits

- [spatie/laravel-backup](https://github.com/spatie/laravel-backup) does the actual backing up, cleanup and monitoring. This package only configures, checks and drills it.
- [wnx/laravel-backup-restore](https://github.com/stefanzweifel/laravel-backup-restore) by Stefan Zweifel gave us the idea of pluggable health checks after a restore. It isn't a dependency: its current release requires PHP 8.4 (this package supports 8.3), and `offsite:verify` needs its own scratch-database guard, manifest awareness and AES diagnostics.
- [itiden/statamic-backup](https://github.com/itiden/statamic-backup) inspired the metadata stored inside each archive.

## License

MIT. See [LICENSE](LICENSE).
