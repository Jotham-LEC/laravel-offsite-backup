# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/Jotham-LEC/laravel-offsite-backup/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/Jotham-LEC/laravel-offsite-backup/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Jotham-LEC/laravel-offsite-backup/releases/tag/v0.1.0
