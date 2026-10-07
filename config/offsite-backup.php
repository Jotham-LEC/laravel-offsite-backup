<?php

use Illuminate\Support\Str;
use Jothamlec\OffsiteBackup\Doctor\Checks;

/*
 * jothamlec/laravel-offsite-backup
 *
 * The hardened config/backup.php written by `php artisan offsite:install --write` reads this
 * file directly (config() isn't loaded yet when backup.php is evaluated), so the name, disk,
 * paths and connections below are the single source of truth for spatie/laravel-backup too.
 */

return [

    /*
     * The folder in the bucket. One per app: give each app its own key, restricted to this prefix.
     */
    'name' => env('OFFSITE_BACKUP_NAME', Str::slug((string) env('APP_NAME', 'laravel'))),

    /*
     * The disk in config/filesystems.php (see `php artisan offsite:install --disk=b2`).
     */
    'disk' => env('OFFSITE_BACKUP_DISK', 'b2'),

    /*
     * The directory the include/exclude paths below are relative to, and the zip's relative_path.
     * null: Deployer's shared/ dir when the app runs from releases/N, else base_path().
     */
    'shared_path' => env('OFFSITE_BACKUP_SHARED_PATH'),

    /*
     * Relative to shared_path (or absolute). '.' is shared_path itself: under Deployer that is
     * .env, storage/ and every other shared dir, without following the release's symlinks.
     */
    'include' => [
        '.',
    ],

    'exclude' => [
        'storage/framework',
        'storage/logs',
        'storage/app/backup-temp',
        // 'storage/statamic',          // the Stache, Glide and search caches; rebuilt after restore
        // 'database/database.sqlite',  // the live SQLite file, when its connection is dumped below
    ],

    /*
     * null: storage_path('app/backup-temp').
     */
    'temporary_directory' => null,

    /*
     * The database connections to dump, by name. Explicit on purpose: never DB_CONNECTION,
     * which is often SQLite on a laptop and PostgreSQL or MySQL in production.
     */
    'connections' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OFFSITE_BACKUP_CONNECTIONS', '')),
    ))),

    'schedule' => [
        'enabled' => (bool) env('OFFSITE_BACKUP_SCHEDULE', true),

        /*
         * 'HH:MM' for backup:clean; backup:run follows 5 minutes later and backup:monitor an hour
         * later. 'auto' derives a time from a hash of the name within the window, to stagger apps.
         */
        'time' => env('OFFSITE_BACKUP_TIME', 'auto'),
        'window' => ['01:00', '05:00'],

        'timezone' => env('OFFSITE_BACKUP_TIMEZONE', 'UTC'),

        'environments' => ['production'],

        /*
         * Schedule backup:clean. Turn it off when the disk's key can't delete (Object Lock):
         * the bucket's lifecycle rules expire old versions instead.
         */
        'clean' => (bool) env('OFFSITE_BACKUP_CLEAN', true),
    ],

    /*
     * Pinged after each scheduled backup:run. Formats:
     *   'kuma'          Uptime Kuma push URL: ?status=up|down&msg=...
     *   'healthchecks'  healthchecks.io: the URL on success, URL/fail on failure
     *   'plain'         the URL on success only
     */
    'heartbeat' => [
        'url' => env('OFFSITE_BACKUP_HEARTBEAT_URL'),
        'format' => env('OFFSITE_BACKUP_HEARTBEAT_FORMAT', 'kuma'),
    ],

    /*
     * Where offsite:heartbeat-tick records the scheduler's last run. null: a file in
     * storage/framework (excluded from the backup).
     */
    'scheduler_tick_path' => null,

    'monitor' => [
        /*
         * backup:monitor's MaximumStorageInMegabytes. offsite:doctor checks it against the
         * estimated archive size times the number of backups the cleanup keeps.
         */
        'max_storage_mb' => (int) env('OFFSITE_BACKUP_MAX_STORAGE_MB', 20000),
    ],

    /*
     * Writes offsite-manifest.json into each archive (before encryption).
     */
    'manifest' => true,

    'doctor' => [
        'checks' => [
            Checks\SchedulerIsAlive::class,
            Checks\RunningUser::class,
            Checks\IncludePathsAreReadable::class,
            Checks\ArchiveEncryption::class,
            Checks\SizeCap::class,
            Checks\PathLayout::class,
            Checks\DiskIsReachable::class,
            Checks\DumpBinaries::class,
            Checks\ConfigCacheIsFresh::class,
            Checks\TimezoneIsExplicit::class,
            Checks\HeartbeatIsConfigured::class,
        ],
    ],

    /*
     * offsite:verify: restore drills. Run them off the server (a laptop, CI or a dedicated box).
     */
    'verify' => [
        /*
         * A connection in config/database.php for PostgreSQL/MySQL restores. Its tables are
         * dropped first. offsite:verify refuses it if it points at a backed-up database.
         */
        'scratch_connection' => env('OFFSITE_VERIFY_SCRATCH_CONNECTION'),

        'minimum_tables' => 1,

        // The newest backup must be younger than this.
        'maximum_age_hours' => 26,

        'minimum_files' => 1,

        /*
         * Paths that must be in the archive, relative to shared_path. The include roots other
         * than '.' are added automatically.
         */
        'expected_paths' => ['.env'],

        /*
         * [class, options] pairs. Add 'dump' => 'pgsql' to an options array to limit a check to
         * the dumps whose file name contains that string.
         */
        'health_checks' => [
            // [\Jothamlec\OffsiteBackup\Verify\HealthChecks\TableHasRows::class, ['table' => 'users', 'min' => 1]],
            // [\Jothamlec\OffsiteBackup\Verify\HealthChecks\NewestRowWithin::class, ['table' => 'orders', 'column' => 'created_at', 'hours' => 48]],
        ],

        /*
         * psql errors that don't fail a restore (the dump's owners and grants don't exist in the
         * scratch database).
         */
        'ignore_restore_errors' => [
            '/role "[^"]+" does not exist/',
            '/must be owner of/',
        ],

        'heartbeat' => [
            'url' => env('OFFSITE_VERIFY_HEARTBEAT_URL'),
            'format' => env('OFFSITE_VERIFY_HEARTBEAT_FORMAT', env('OFFSITE_BACKUP_HEARTBEAT_FORMAT', 'kuma')),
        ],
    ],

];
