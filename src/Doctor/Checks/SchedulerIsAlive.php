<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Carbon\CarbonImmutable;
use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\SchedulerTick;

class SchedulerIsAlive implements Check
{
    public const MAX_AGE_SECONDS = 120;

    public function __construct(private readonly SchedulerTick $tick) {}

    public function name(): string
    {
        return 'Scheduler is running';
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! config('offsite-backup.schedule.enabled')) {
            return CheckResult::warn(
                'offsite-backup.schedule.enabled is off: this package schedules nothing.',
                'Schedule backup:clean, backup:run, backup:monitor and offsite:heartbeat-tick yourself, or turn it on.',
            );
        }

        $environments = array_values(array_filter((array) config('offsite-backup.schedule.environments', []), 'is_string'));

        if ($environments !== [] && ! app()->environment($environments)) {
            return CheckResult::warn(
                sprintf("APP_ENV '%s' isn't in schedule.environments [%s]: no backups are scheduled here.", app()->environment(), implode(', ', $environments)),
                'Expected on a laptop or staging. In production, set APP_ENV or offsite-backup.schedule.environments.',
            );
        }

        $last = $this->tick->last();

        if ($last === null) {
            return CheckResult::warn(
                "offsite:heartbeat-tick has never run ({$this->tick->path()} doesn't exist).",
                'Install the scheduler (`dep offsite:scheduler`, or a cron line running `php artisan schedule:run` every minute) and run doctor again after a minute.',
            );
        }

        $age = (int) $last['at']->diffInSeconds(CarbonImmutable::now(), true);
        $user = $last['user'] !== null ? " as {$last['user']}" : '';

        if ($age > self::MAX_AGE_SECONDS) {
            return CheckResult::fail(
                "The scheduler last ran {$last['at']->diffForHumans()}{$user} ({$last['at']->toIso8601String()}).",
                'Check `crontab -l`, /etc/cron.d and `systemctl list-timers` for a schedule:run line pointing at this app, and its log for errors.',
            );
        }

        return CheckResult::pass("The scheduler ran {$age}s ago{$user}.");
    }
}
