<?php

namespace Jothamlec\OffsiteBackup\Verify\HealthChecks;

use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;

/**
 * A check run against each restored database, after the idea in wnx/laravel-backup-restore.
 * Configured in offsite-backup.verify.health_checks as [class, options] pairs.
 */
interface HealthCheck
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(array $options = []);

    public function name(): string;

    public function check(RestoredDatabase $database): CheckResult;
}
