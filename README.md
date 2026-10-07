# laravel-offsite-backup

A Deployer recipe and hardened preset for [spatie/laravel-backup](https://github.com/spatie/laravel-backup), with pre-flight checks and off-server restore drills.

This is **not** a backup engine. spatie/laravel-backup takes the backups, cleans them up and monitors them. This package adds what we kept rebuilding around it across production apps:

- **`offsite:install`** publishes a hardened `config/backup.php`, derived from spatie's own config file, plus disk presets for Backblaze B2, Cloudflare R2, Amazon S3 and Wasabi.
- **Scheduling** of `backup:clean`, `backup:run` (with heartbeat pings), `backup:monitor`, and a scheduler heartbeat.
- **`offsite:doctor`** runs eleven pre-flight checks that catch the failures that otherwise surface weeks later.
- **`offsite:verify`** is a restore drill you run *off* the server. It downloads a backup, decrypts it, restores the dumps into throwaway databases and checks them.
- **`offsite-manifest.json`** in every archive records what was backed up, what was skipped and why.
- **`recipe/offsite-backup.php`** is a Deployer recipe. It writes `.env` settings from 1Password, Doppler, files or env vars, installs the scheduler without doubling it, and runs the commands remotely.

## Why

Every item below happened in production, on apps that "had backups":

- **No scheduler.** `backup:run` was scheduled, but nothing ran `schedule:run`. On another box, `/etc/cron.d` already ran it and a second crontab line would have run every task twice.
- **Unreadable files.** The scheduler ran as `www-data` from a systemd timer and couldn't read `.env` or parts of `storage/`. spatie's Finder stops at the first unreadable directory, *including directories you excluded*.
- **libzip without AES.** Many PHP builds can't do AES. With `'encryption' => 'default'` that is easy to miss until the first backup.
- **Size-cap pruning.** spatie's default `delete_oldest_backups_when_using_more_megabytes_than => 5000` quietly deletes the monthly backups the retention promises to keep.
- **Symlinks.** Under Deployer, `base_path()` is `releases/N` and `storage/` is a symlink. With `follow_links => false`, including `base_path()` skips all of storage.
- **B2 checksums.** Newer AWS SDKs send CRC checksums by default, which B2 (and other non-AWS endpoints) reject. The disk needs `request_checksum_calculation` and `response_checksum_validation` set to `when_required`.
- **Object Lock.** Locked objects can't be deleted, so a write probe stays until its retention ends. A key without delete rights also makes `backup:clean` fail.
- Also: `DB_CONNECTION` was SQLite locally and PostgreSQL in production; `pg_dump` was older than the server; `.env` changed after `config:cache`; a staging host sharing the crontab would have uploaded under the production name.

## Requirements

- PHP 8.3+ with `ext-zip`. AES-256 needs libzip built with a crypto backend; `offsite:doctor` checks it.
- Laravel 12 or 13
- spatie/laravel-backup ^10
- `league/flysystem-aws-s3-v3` for the S3-compatible disks
- The dump tools for your databases (`sqlite3`, `pg_dump`, `mysqldump`/`mariadb-dump`) on the server, and the matching clients (`sqlite3`, `psql`, `mysql`) wherever you run `offsite:verify`
- Deployer 8 for the recipe (optional)

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
| `heartbeat.url` / `format` | none / `kuma` | `kuma`, `healthchecks` or `plain` |
| `monitor.max_storage_mb` | `20000` | spatie's `MaximumStorageInMegabytes` |
| `manifest` | `true` | Add `offsite-manifest.json` to archives |
| `doctor.checks` | the 11 checks below | Add or remove classes implementing `Doctor\Check` |
| `verify.*` | | See [offsite:verify](#offsiteverify) |

For a slow PostgreSQL dump, set a timeout on the connection in `config/database.php`, which spatie passes to `pg_dump`: `'dump' => ['timeout' => 900]`. Set `dump_binary_path` there too when the right `pg_dump` isn't first on the scheduler's `PATH`.

## Disk presets

`php artisan offsite:install --disk=b2|r2|s3|wasabi` prints a disk for `config/filesystems.php` and the `.env` keys it reads. Every preset sets `'throw' => true`. Non-AWS endpoints (B2, R2, Wasabi) also set `request_checksum_calculation` and `response_checksum_validation` to `when_required`, and `offsite:doctor` fails if a non-AWS S3 disk lacks them. The S3 preset is named `s3-offsite` so it doesn't collide with Laravel's `s3` disk.

## Security

- **One key per app, limited to its folder.** On B2 (CLI v4): `b2 key create --bucket <bucket> --name-prefix <name>/ <key-name> listBuckets,listFiles,readFiles,writeFiles` (add `deleteFiles` only without Object Lock; never grant `bypassGovernance` or `writeFileRetentions` to an app key). On S3, use an IAM policy scoped to `arn:aws:s3:::<bucket>/<name>/*` and `s3:prefix` `<name>/`. R2 API tokens with Object Read & Write can be limited to specific buckets but not to prefixes: use one bucket per app. A compromised server should only be able to touch its own backups.
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
| `backup:monitor` | T + 60 min | `withoutOverlapping` |
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
| 3 | Include paths readable | the first unreadable file or directory, with owner and mode (unreadable directories count even when excluded) |
| 4 | Archive encryption | libzip lacks AES-256; encryption is `none` (FAIL) or `default` (WARN); no password |
| 5 | Size cap and monitor | the cap is below estimated archive size x backups kept (FAIL); the monitor's limit is below it, or no monitor watches this backup (WARN) |
| 6 | Paths and symlinks | include outside `base_path()` without `relative_path`; a symlinked include path; symlinked directories that are skipped; `temporary_directory` inside an include and not excluded |
| 7 | Disk reachable | a read-only listing of `<name>/` fails; non-AWS endpoint without `when_required`; `throw` off |
| 8 | Dump binaries | `sqlite3` / `pg_dump` / `mysqldump` / `mariadb-dump` missing (honours `dump_binary_path`); `pg_dump` major older than `SHOW server_version_num` |
| 9 | Config cache | `.env` is newer than the cached config (WARN) |
| 10 | Schedule timezone | no explicit timezone (WARN); shows the resolved times |
| 11 | Heartbeat | no heartbeat URL (WARN) |

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

1. Lists `<name>/` on the disk through Flysystem (a listing error is reported, not mistaken for an empty bucket) and picks the newest, or `--backup`. The newest must be younger than `verify.maximum_age_hours` (26).
2. Streams it to a temp dir and opens it with `ZipArchive` and `BACKUP_ARCHIVE_PASSWORD`. It fails clearly when this PHP's libzip can't decrypt AES, when the password is missing, or when extraction fails or comes out empty (wrong password). An unencrypted archive is a WARN.
3. Reads `offsite-manifest.json`.
4. File checks: `verify.expected_paths` (default `.env`) and the relative include roots are present, and there are at least `verify.minimum_files` files.
5. For each dump in `db-dumps/`, it rejects truncated dumps (no end-of-dump marker), then restores:
   - **SQLite** into a temp file with the `sqlite3` CLI, then `PRAGMA integrity_check` and row counts per table.
   - **PostgreSQL / MySQL / MariaDB** only into `verify.scratch_connection`, a throwaway database whose tables are dropped first, via `psql` / `mysql`. It **refuses** when the scratch connection's host, port and database match any backed-up connection, whether from this app's config or from the archive's manifest. `psql` errors about missing roles and ownership are ignored (`verify.ignore_restore_errors`); others fail the restore.
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
    'BACKUP_ARCHIVE_PASSWORD' => 'op://Ops/my-app backup/archivePassword',
    'B2_ACCESS_KEY_ID' => 'op://Ops/my-app backup/keyID',
    'B2_SECRET_ACCESS_KEY' => 'doppler:B2_SECRET_ACCESS_KEY',
    'B2_BUCKET' => 'env:B2_BUCKET',
    'B2_REGION' => 'us-west-004',
    'B2_ENDPOINT' => 'https://s3.us-west-004.backblazeb2.com',
    'OFFSITE_BACKUP_CONNECTIONS' => 'pgsql',
    'OFFSITE_BACKUP_HEARTBEAT_URL' => 'op://Ops/my-app backup/heartbeat?', // trailing ? = optional
]);
set('offsite_reader_user', 'www-data');      // for offsite:acl, when the scheduler isn't the deploy user
set('offsite_cron_umask', '002');            // optional: keep files the scheduler creates group-writable
```

Secret sources: `env:VAR` (local environment), `op://...` (`op read`), `doppler:NAME` (`doppler secrets get NAME --plain`), `file:/path` (trimmed), `literal:...`, a closure, or a plain string. Values may not contain single quotes or newlines.

| Task | Runs | |
| --- | --- | --- |
| `offsite:env` | server | Writes a marked block into `{{deploy_path}}/shared/.env` *in place* (`cat >`, so owner, mode and ACLs survive), replacing the previous block; adds `OFFSITE_BACKUP_NAME` and `OFFSITE_BACKUP_SHARED_PATH`; then `config:cache` if `current` exists |
| `offsite:acl` | server | `setfacl` read access for `offsite_reader_user` on `.env` and `offsite_acl_paths` (default `storage`), including default ACLs on directories |
| `offsite:scheduler` | server | Refuses when `/etc/cron.d/*`, a systemd unit or an unmarked crontab line already runs `schedule:run` for this deploy path (`--force` overrides). Otherwise adds the line through `contrib/crontab.php` (`crontab:sync`) when loaded, or as its own marked crontab line |
| `offsite:doctor` / `offsite:run` / `offsite:list` | server | `artisan offsite:doctor` / `backup:run` / `backup:list` in `current` |
| `offsite:verify` | locally | `php artisan offsite:verify --name=<offsite_name>` in your local project, with the resolved secrets as environment variables, so your laptop needs no production `.env` |

Every task refuses hosts outside `offsite_stages`. The recipe's logic lives in plain, tested classes under `src/Deployer/`; the recipe itself is thin glue.

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
- **After a restore:** `php please stache:refresh`, `php please search:update --all`, `php please glide:clear`, and `php please static:clear` when static caching is on.

## Testing

```bash
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan (Larastan) level 8
```

The tests run spatie's real `backup:run` against a sandboxed SQLite app and verify the archive. The AES round-trip tests skip when the local libzip has no AES; CI reports which applies.

## Credits

- [spatie/laravel-backup](https://github.com/spatie/laravel-backup) does the actual backing up, cleanup and monitoring. This package only configures, checks and drills it.
- [wnx/laravel-backup-restore](https://github.com/stefanzweifel/laravel-backup-restore) by Stefan Zweifel gave us the idea of pluggable health checks after a restore. It isn't a dependency: its current release requires PHP 8.4 (this package supports 8.3), and `offsite:verify` needs its own scratch-database guard, manifest awareness and AES diagnostics.
- [itiden/statamic-backup](https://github.com/itiden/statamic-backup) inspired the metadata stored inside each archive.

## License

MIT. See [LICENSE](LICENSE).
