<?php

namespace Jothamlec\OffsiteBackup\Install;

use Composer\InstalledVersions;
use RuntimeException;
use Spatie\Backup\BackupServiceProvider;

/**
 * Derives the hardened config/backup.php from the config file spatie/laravel-backup ships.
 *
 * Every rewrite must match the expected number of times. When spatie changes the file's shape,
 * rendering fails loudly instead of producing a half-hardened config.
 */
final class HardenedBackupConfig
{
    public function __construct(private readonly ?string $spatieConfigPath = null) {}

    public static function spatieConfigPath(): string
    {
        $reflection = new \ReflectionClass(BackupServiceProvider::class);

        return dirname((string) $reflection->getFileName(), 2).'/config/backup.php';
    }

    public function render(): string
    {
        $path = $this->spatieConfigPath ?? self::spatieConfigPath();
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("Can't read spatie/laravel-backup's config at {$path}.");
        }

        return $this->transform($source);
    }

    public function transform(string $source): string
    {
        foreach ($this->rewrites() as $description => [$pattern, $replacement, $expected]) {
            $result = preg_replace_callback(
                $pattern,
                fn (array $match): string => is_callable($replacement) ? $replacement($match) : $replacement,
                $source,
                -1,
                $count,
            );

            if ($result === null || $count !== $expected) {
                throw new RuntimeException(sprintf(
                    'spatie/laravel-backup\'s config/backup.php has changed shape: expected %d match(es) for "%s", found %d. '
                    .'Publish spatie\'s config and apply the settings listed in the README by hand, and please open an issue.',
                    $expected,
                    $description,
                    $count,
                ));
            }

            $source = $result;
        }

        return $source;
    }

    /**
     * @return array<string, array{0: string, 1: string|callable(array<int|string, string>): string, 2: int}>
     */
    private function rewrites(): array
    {
        $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('spatie/laravel-backup')
            ? (string) InstalledVersions::getPrettyVersion('spatie/laravel-backup')
            : 'unknown';

        $header = <<<PHP
            /*
             * spatie/laravel-backup's config ({$version}), hardened by jothamlec/laravel-offsite-backup:
             * AES-256, verified zips with retries, gzipped dumps, failure-only notifications, no size cap,
             * and the name, disk, paths and connections from config/offsite-backup.php.
             * `php artisan offsite:doctor` checks it.
             */
            \$offsite = Preset::load(__DIR__.'/offsite-backup.php');
            PHP;

        return [
            'imports' => [
                '/^(use Spatie\\\\Backup\\\\Tasks\\\\Monitor\\\\HealthChecks\\\\MaximumStorageInMegabytes;\n)/m',
                fn (array $m): string => "use Jothamlec\\OffsiteBackup\\Support\\Preset;\n".$m[1]."use Spatie\\DbDumper\\Compressors\\GzipCompressor;\n\n".$header."\n",
                1,
            ],
            'name' => ["/'name' => env\\('APP_NAME', 'laravel-backup'\\),/", "'name' => \$offsite['name'],", 2],
            'include' => ["/'include' => \\[[^\\]]*\\],/", "'include' => Preset::include(\$offsite),", 1],
            'exclude' => ["/'exclude' => \\[[^\\]]*\\],/", "'exclude' => Preset::exclude(\$offsite),", 1],
            'relative_path' => ["/'relative_path' => null,/", "'relative_path' => Preset::sharedPath(\$offsite),", 1],
            'databases' => [
                "/'databases' => \\[[^\\]]*\\],/",
                "// Explicit, from config/offsite-backup.php: never DB_CONNECTION.\n            'databases' => Preset::connections(\$offsite),",
                1,
            ],
            'database_dump_compressor' => ["/'database_dump_compressor' => null,/", "'database_dump_compressor' => GzipCompressor::class,", 1],
            'destination disks' => ["/'disks' => \\[\\s*'local',\\s*\\],/", "'disks' => [\$offsite['disk']],", 1],
            'temporary_directory' => ["/'temporary_directory' => [^\\n]*,/", "'temporary_directory' => Preset::temporaryDirectory(\$offsite),", 1],
            'encryption' => ["/'encryption' => '[a-z0-9]+',/", "'encryption' => 'aes256',", 1],
            'verify_backup, tries, retry_delay' => [
                "/('verify_backup' => )\\w+(,.*?'tries' => )\\d+(,.*?'retry_delay' => )\\d+/s",
                fn (array $m): string => $m[1].'true'.$m[2].'3'.$m[3].'60',
                1,
            ],
            'success notifications' => [
                '/((?:BackupWasSuccessful|HealthyBackupWasFound|CleanupWasSuccessful)Notification::class => )\[[^\]]*\]/',
                fn (array $m): string => $m[1].'[]',
                3,
            ],
            'mail to' => ["/'to' => '[^']*',/", "'to' => env('BACKUP_NOTIFY_EMAIL', 'your@example.com'),", 1],
            'monitor disks' => ["/'disks' => \\['local'\\],/", "'disks' => [\$offsite['disk']],", 1],
            'monitor storage' => [
                '/(?<![\\\\\w])MaximumStorageInMegabytes::class => \d+,/',
                "MaximumStorageInMegabytes::class => \$offsite['monitor']['max_storage_mb'] ?? 20000,",
                1,
            ],
            'keep_all_backups_for_days' => ["/'keep_all_backups_for_days' => \\d+,/", "'keep_all_backups_for_days' => 7,", 1],
            'keep_daily_backups_for_days' => ["/'keep_daily_backups_for_days' => \\d+,/", "'keep_daily_backups_for_days' => 23,", 1],
            'keep_weekly_backups_for_weeks' => ["/'keep_weekly_backups_for_weeks' => \\d+,/", "'keep_weekly_backups_for_weeks' => 0,", 1],
            'keep_monthly_backups_for_months' => ["/'keep_monthly_backups_for_months' => \\d+,/", "'keep_monthly_backups_for_months' => 12,", 1],
            'keep_yearly_backups_for_years' => ["/'keep_yearly_backups_for_years' => \\d+,/", "'keep_yearly_backups_for_years' => 0,", 1],
            'size cap' => [
                "/'delete_oldest_backups_when_using_more_megabytes_than' => [^,\\n]+,/",
                "'delete_oldest_backups_when_using_more_megabytes_than' => null,",
                1,
            ],
        ];
    }
}
