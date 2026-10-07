# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.2] - 2026-10-08

### Fixed

- **`offsite:acl` could remove write access.** It ran `setfacl -R -m u:<reader>:rX`, which replaces the user's entry: an existing `u:www-data:rwx` on `storage` became `r-x`, and a new named entry also overrode the write access www-data had through its group. The task now reads each path's ACL (`getfacl`) and the user's groups (`id -nG`), leaves paths the user can already read alone, and elsewhere sets the union of the current entry, the current access and `r` (`rx` on directories). Default ACLs get the same treatment. It never narrows a permission, skips symlinks, reports owned-but-unreadable paths, and prints counts. See the README if an earlier version already ran on your server.
- `offsite_acl_paths` now defaults to `[]`: `offsite:acl` touches only `.env` unless you list paths such as `storage`.
- **Slow `backup:list`, `backup:clean` and `backup:monitor` with a media mirror.** spatie lists `<name>/` recursively and sends a HEAD request (`mimeType`) for every non-zip object (`BackupCollection::createFromFiles` → `File::isZipFile`). With 3,546 objects under `<name>/media/`, each command took about 25 minutes. `offsite:mirror` now writes to a sibling prefix, `<name>-media/` by default (`mirror.destination`, `OFFSITE_MIRROR_DESTINATION`), and warns when the destination is inside `<name>/`.
- `offsite:verify` and `offsite:doctor`'s disk check list only the top level of `<name>/` and keep only `*.zip`; they never list recursively or ask for MIME types.
- Test sandboxes (`/tmp/offsite-backup-tests-*`, with fixture `.env` files) leaked when a run was interrupted. A temporary-directory helper now deletes them in a `finally` block, at exit and on SIGINT/SIGTERM/SIGHUP, and sweeps sandboxes older than six hours left by killed runs.

### Added

- `php artisan offsite:mirror --relocate-from=<name>/media [--dry-run]` moves a v0.2.0/0.2.1 mirror to the new destination. It does a server-side copy (Flysystem `copy()`, i.e. S3 CopyObject, with no egress on B2), checks the size, then deletes the old key (on a B2 Object Lock bucket that only hides the old version, which removes it from listings). It is idempotent and resumable, prints counts and refuses to move `<name>/` itself.
- `offsite:doctor` warns when non-zip objects sit under `<name>/` (from a recursive listing capped at 1,000 objects) and suggests the relocation command.
- `schedule.monitor_time` (`OFFSITE_BACKUP_MONITOR_TIME`): `backup:monitor` at a fixed time instead of an hour after `backup:clean`.

### Upgrading

- With a mirror under `<name>/media/`: make sure the backup key can write `<name>-media/` (a B2 key created with `--name-prefix <name>/` can't; recreate it with `--name-prefix <name>`). Then run `php artisan offsite:mirror --relocate-from=<name>/media` **before** the next scheduled mirror; otherwise the mirror copies everything again. Or set `OFFSITE_MIRROR_DESTINATION=<name>/media` to keep the old layout.
- A published `config/offsite-backup.php` needs no changes: the new keys fall back to their defaults.

### Documentation

- Statamic apps should run `offsite:verify` locally with `CACHE_STORE=array`.

## [0.2.1] - 2026-10-08

### Fixed

- `offsite:env` and `offsite:acl` failed when `deploy_path` started with `~`: the path was shell-quoted (`cd '~/app/shared'`), and `offsite:env` left the uploaded block file, secrets included, on the server. Both now resolve the shared path once with an unquoted `cd {{deploy_path}}/shared && pwd -P` and use the absolute result; `offsite:env` deletes the uploaded block (and any half-written `.env.offsite-new`) when the upload or merge fails.
- `offsite:install --write --force` reset customised values such as the notification address back to `your@example.com`. It now carries over `backup.name`, the first monitor's `name`, `notifications.mail.to` and the mail `from` address and name when they differ from spatie's and the hardened defaults, and lists what it kept.
- The generated `config/backup.php` sorts its `use` imports, so Pint leaves it alone.
- `offsite:verify` couldn't decrypt AES archives on a PHP whose libzip lacks AES (common with Nix and Homebrew). It now extracts with 7-Zip (`7zz`, `7z` or `7za`) in that case, with the password on stdin rather than the command line, and otherwise fails with an install hint.
- `offsite:doctor`'s archive encryption check warns instead of failing about libzip's missing AES where `APP_ENV` isn't in `schedule.environments` (a machine that only verifies), and says whether libzip or 7-Zip can decrypt there. Where backups are scheduled it still fails.

### Documentation

- Deployer's `-o key=value` truncates values at the next `=`; use `offsite_secrets_file` for secrets.

## [0.2.0] - 2026-10-08

### Added

- `offsite:mirror`: copies a disk (e.g. an R2 media bucket) to the backup disk under `<name>/media/<path>`, copying only missing or size-differing objects, streamed, never deleting; prints counts and exits non-zero on errors. Optional daily schedule (`mirror.enabled`, `source`, `prefix`, `time`) that pings the heartbeat's failure URL. The layout matches existing hand-rolled `backup:mirror-media` copies.
- `offsite:verify` reads the dump's pg_dump and server major versions, restores with a `psql` at least as new as the pg_dump (searching versioned installs), and falls back to `psql` from the `postgres:<pg_dump major>` image. Without a scratch connection, PostgreSQL dumps restore into a throwaway `postgres:<pg_dump major>` container (`verify.docker`, `verify.docker_image`).
- Doctor checks `DatabasesAreExplicit` (fails when `backup.source.databases` comes from `DB_CONNECTION`) and `MailAddressesAreValid` (fails on a sender or recipient spatie would reject, such as `hello@{{DOMAIN}}`). A published `config/offsite-backup.php` needs them added to `doctor.checks`.
- Recipe settings `offsite_secrets_file` (local `KEY=VALUE` file, refused when group or world readable), `offsite_env_extra` (non-secret keys) and `offsite_legacy_markers` (hand-rolled `.env` blocks that `offsite:env` removes; default `# >>> off-site backup` / `# <<< off-site backup`).
- README sections on Docker/Dokploy and on migrating from a hand-rolled spatie setup.

### Changed

- `SecretResolver::resolveAll()` reads all `op://` sources with one `op inject` (template on stdin), so 1Password asks for approval once per run; values round-trip exactly. Commands run through Symfony Process; the constructor now takes an optional `(argv, stdin)` runner instead of a `runLocally` callable.
- Missing-role and ownership errors are always ignored in PostgreSQL restores and reported as info with counts; when the scratch server is older than the pg_dump, settings it doesn't recognise (`transaction_timeout`) are ignored too.
- "Include paths readable" reports every unreadable path (up to 20), marks the excluded ones, and no longer suggests excluding a directory, which doesn't help: spatie's Finder opens excluded directories before filtering.
- An error while checking one restored dump fails that dump's step instead of the whole run.

### Fixed

- `dep offsite:verify` ran the local command through `runLocally(env: ...)`, which puts `export KEY='secret'` in front of the command: a failing verify printed every secret in Deployer's error. It now passes the settings in the process environment, streams the output, and works on Deployer 8 and 7.5 (7.5's `runLocally()` rejects `forceOutput`). It refuses to run when the local config is cached.
- `offsite:env` refuses to rewrite `.env` when a block's start marker has no end marker, instead of letting `sed` delete to the end of the file.

## [0.1.0] - 2026-10-07

### Added

- `offsite:install`: publishes `config/offsite-backup.php`; prints or writes (`--write`, `--force`) a hardened `config/backup.php` derived from spatie/laravel-backup's own config; prints b2, r2, s3 and wasabi disk presets (`--disk`).
- Scheduling of `backup:clean`, `backup:run` (heartbeat pings in Uptime Kuma, healthchecks.io or plain format), `backup:monitor` and `offsite:heartbeat-tick`, with `auto` times staggered by a hash of the backup name.
- `offsite:doctor` with eleven configurable checks and `--json` / `--write-probe`.
- `offsite:verify`: downloads, decrypts and test-restores a backup (SQLite into a temp file; PostgreSQL/MySQL into a guarded scratch connection), with pluggable health checks, file checks, `--json` and a verify heartbeat.
- `offsite-manifest.json` inside each archive (before encryption) via spatie's `BackupManifestWasCreated` event.
- Deployer recipe `recipe/offsite-backup.php`: `offsite:env`, `offsite:acl`, `offsite:scheduler`, `offsite:doctor`, `offsite:run`, `offsite:list`, `offsite:verify`, with pluggable secret sources and a stage guard.

[Unreleased]: https://github.com/Jotham-LEC/laravel-offsite-backup/compare/v0.2.2...HEAD
[0.2.2]: https://github.com/Jotham-LEC/laravel-offsite-backup/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/Jotham-LEC/laravel-offsite-backup/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/Jotham-LEC/laravel-offsite-backup/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Jotham-LEC/laravel-offsite-backup/releases/tag/v0.1.0
