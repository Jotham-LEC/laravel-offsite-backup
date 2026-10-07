<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use InvalidArgumentException;
use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\Heartbeat;

class HeartbeatIsConfigured implements Check
{
    public function name(): string
    {
        return 'Heartbeat';
    }

    public function run(DoctorContext $context): CheckResult
    {
        try {
            $heartbeat = Heartbeat::fromConfig((array) config('offsite-backup.heartbeat', []));
        } catch (InvalidArgumentException $exception) {
            return CheckResult::fail($exception->getMessage());
        }

        if (! $heartbeat->enabled()) {
            return CheckResult::warn(
                'No heartbeat URL: if the scheduler or backup:run stops, nothing tells you.',
                'Set OFFSITE_BACKUP_HEARTBEAT_URL to a push monitor (Uptime Kuma, healthchecks.io) expecting a ping every 24h.',
            );
        }

        return CheckResult::pass("Pinging {$heartbeat->format} at ".parse_url((string) $heartbeat->url, PHP_URL_HOST).' after each backup:run.');
    }
}
