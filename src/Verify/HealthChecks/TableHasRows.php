<?php

namespace Jothamlec\OffsiteBackup\Verify\HealthChecks;

use InvalidArgumentException;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;
use Throwable;

class TableHasRows implements HealthCheck
{
    private string $table;

    private int $min;

    public function __construct(array $options = [])
    {
        $this->table = is_string($options['table'] ?? null) ? $options['table'] : throw new InvalidArgumentException('TableHasRows needs a table.');
        $this->min = (int) ($options['min'] ?? 1);
    }

    public function name(): string
    {
        return "{$this->table} has at least {$this->min} rows";
    }

    public function check(RestoredDatabase $database): CheckResult
    {
        try {
            $count = $database->db()->table($this->table)->count();
        } catch (Throwable $exception) {
            return CheckResult::fail("{$this->table}: ".strtok($exception->getMessage(), "\n"));
        }

        return $count >= $this->min
            ? CheckResult::pass("{$this->table}: {$count} rows")
            : CheckResult::fail("{$this->table}: {$count} rows, expected at least {$this->min}");
    }
}
