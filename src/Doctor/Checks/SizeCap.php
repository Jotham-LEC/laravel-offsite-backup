<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\DatabaseInspector;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

class SizeCap implements Check
{
    public function __construct(private readonly DatabaseInspector $inspector) {}

    public function name(): string
    {
        return 'Size cap and monitor';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $bytes = $context->walk()->bytes;

        foreach (array_filter((array) config('backup.backup.source.databases', []), 'is_string') as $connection) {
            $bytes += $this->inspector->sizeInBytes($connection) ?? 0;
        }

        $kept = self::backupsKept((array) config('backup.cleanup.default_strategy', []));
        $archiveMb = $bytes / 1048576;
        $projectedMb = (int) ceil($archiveMb * $kept);
        $estimate = sprintf('~%s MB per archive (uncompressed estimate) x %d kept = ~%d MB', number_format($archiveMb, 1), $kept, $projectedMb);

        $results = [];
        $cap = config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than');

        if ($cap !== null && (int) $cap <= $projectedMb) {
            $results[] = CheckResult::fail(
                "The size cap ({$cap} MB) is below the projected storage ({$estimate}): backup:clean will delete backups the retention promises to keep.",
                'Set cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than to null (retention and the bucket\'s lifecycle do the job), or raise it.',
            );
        }

        $monitor = $this->monitorLimit();

        if ($monitor === false) {
            $results[] = CheckResult::warn(
                "No monitor_backups entry for '".config('backup.backup.name')."': backup:monitor won't watch this backup.",
                'The hardened config/backup.php has one (php artisan offsite:install).',
            );
        } elseif ($monitor !== null && $monitor <= $projectedMb) {
            $results[] = CheckResult::warn(
                "backup:monitor's MaximumStorageInMegabytes ({$monitor} MB) is below the projected storage ({$estimate}): it will report the backups unhealthy.",
                'Raise OFFSITE_BACKUP_MAX_STORAGE_MB (offsite-backup.monitor.max_storage_mb).',
            );
        }

        return CheckResult::combine($results, ucfirst($estimate).'; cap '.($cap === null ? 'none' : "{$cap} MB").', monitor '.($monitor === null ? 'none' : "{$monitor} MB").'.');
    }

    /**
     * One backup a day: the days kept whole, then one per day, week, month and year.
     *
     * @param  array<mixed>  $strategy
     */
    public static function backupsKept(array $strategy): int
    {
        $keys = [
            'keep_all_backups_for_days',
            'keep_daily_backups_for_days',
            'keep_weekly_backups_for_weeks',
            'keep_monthly_backups_for_months',
            'keep_yearly_backups_for_years',
        ];

        return max(1, array_sum(array_map(fn (string $key): int => (int) ($strategy[$key] ?? 0), $keys)));
    }

    /**
     * The monitor's limit in MB, null when it has no storage check, false when the backup isn't monitored.
     */
    private function monitorLimit(): int|false|null
    {
        $name = config('backup.backup.name');

        foreach ((array) config('backup.monitor_backups', []) as $monitor) {
            if (! is_array($monitor) || ($monitor['name'] ?? null) !== $name) {
                continue;
            }

            $limit = $monitor['health_checks'][MaximumStorageInMegabytes::class] ?? null;

            if (is_array($limit)) {
                $limit = reset($limit);
            }

            return is_numeric($limit) ? (int) $limit : null;
        }

        return false;
    }
}
