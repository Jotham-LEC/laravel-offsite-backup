<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Scheduling\ScheduleRegistrar;

class TimezoneIsExplicit implements Check
{
    public function name(): string
    {
        return 'Schedule timezone';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $timezone = config('offsite-backup.schedule.timezone');
        $times = (new ScheduleRegistrar((array) config('offsite-backup')))->times();
        $summary = "clean {$times['clean']}, run {$times['run']}, monitor {$times['monitor']}";

        if (! is_string($timezone) || $timezone === '') {
            return CheckResult::warn(
                "No schedule timezone: {$summary} follow app.timezone (".config('app.timezone').') and move with it.',
                "Set offsite-backup.schedule.timezone (default 'UTC').",
            );
        }

        if (! in_array($timezone, timezone_identifiers_list(), true) && $timezone !== 'UTC') {
            return CheckResult::fail("Schedule timezone '{$timezone}' isn't a valid identifier.", 'Use e.g. UTC or Europe/Amsterdam.');
        }

        return CheckResult::pass("{$summary} {$timezone}.");
    }
}
