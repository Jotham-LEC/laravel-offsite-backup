<?php

namespace Jothamlec\OffsiteBackup\Verify\HealthChecks;

use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;

class MinimumTables implements HealthCheck
{
    private int $min;

    public function __construct(array $options = [])
    {
        $this->min = (int) ($options['min'] ?? 1);
    }

    public function name(): string
    {
        return "at least {$this->min} tables";
    }

    public function check(RestoredDatabase $database): CheckResult
    {
        $count = count($database->tables());

        return $count >= $this->min
            ? CheckResult::pass("{$count} tables")
            : CheckResult::fail("{$count} tables, expected at least {$this->min}");
    }
}
