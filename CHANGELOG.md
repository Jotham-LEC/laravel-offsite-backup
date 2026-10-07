# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-07

### Added

- `offsite:install`: publishes `config/offsite-backup.php`; prints or writes (`--write`, `--force`) a hardened `config/backup.php` derived from spatie/laravel-backup's own config; prints b2, r2, s3 and wasabi disk presets (`--disk`).
- Scheduling of `backup:clean`, `backup:run` (heartbeat pings in Uptime Kuma, healthchecks.io or plain format), `backup:monitor` and `offsite:heartbeat-tick`, with `auto` times staggered by a hash of the backup name.
- `offsite:doctor` with eleven configurable checks and `--json` / `--write-probe`.
- `offsite:verify`: downloads, decrypts and test-restores a backup (SQLite into a temp file; PostgreSQL/MySQL into a guarded scratch connection), with pluggable health checks, file checks, `--json` and a verify heartbeat.
- `offsite-manifest.json` inside each archive (before encryption) via spatie's `BackupManifestWasCreated` event.
- Deployer recipe `recipe/offsite-backup.php`: `offsite:env`, `offsite:acl`, `offsite:scheduler`, `offsite:doctor`, `offsite:run`, `offsite:list`, `offsite:verify`, with pluggable secret sources and a stage guard.

[Unreleased]: https://github.com/Jotham-LEC/laravel-offsite-backup/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Jotham-LEC/laravel-offsite-backup/releases/tag/v0.1.0
