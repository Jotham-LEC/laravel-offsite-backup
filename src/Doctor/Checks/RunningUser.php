<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\SchedulerTick;
use Jothamlec\OffsiteBackup\Support\SystemUser;

class RunningUser implements Check
{
    public function __construct(
        private readonly SystemUser $user,
        private readonly SchedulerTick $tick,
    ) {}

    public function name(): string
    {
        return 'OS user';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $current = $this->user->current();
        $scheduler = $this->tick->last()['user'] ?? null;

        if ($scheduler === null) {
            return CheckResult::warn(
                "Running as {$current}. The scheduler's user is unknown; if it differs (www-data from a systemd timer, say), these checks don't reflect its permissions.",
                'Once offsite:heartbeat-tick has run, doctor compares the two. Or run doctor as the scheduler\'s user: sudo -u <user> php artisan offsite:doctor.',
            );
        }

        if ($scheduler !== $current) {
            return CheckResult::warn(
                "Running as {$current}, but the scheduler runs as {$scheduler}: the readability checks used {$current}'s permissions.",
                "Run `sudo -u {$scheduler} php artisan offsite:doctor`, or give {$scheduler} read access to .env and the include paths (`dep offsite:acl`).",
            );
        }

        return CheckResult::pass("Running as {$current}, the scheduler's user.");
    }
}
