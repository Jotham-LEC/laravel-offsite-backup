<?php

namespace Jothamlec\OffsiteBackup\Verify\HealthChecks;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;
use Throwable;

/**
 * The newest row's timestamp is recent: catches a backup of a database that stopped being written to,
 * or of the wrong database.
 */
class NewestRowWithin implements HealthCheck
{
    private string $table;

    private string $column;

    private int $hours;

    public function __construct(array $options = [])
    {
        $this->table = is_string($options['table'] ?? null) ? $options['table'] : throw new InvalidArgumentException('NewestRowWithin needs a table.');
        $this->column = is_string($options['column'] ?? null) ? $options['column'] : 'created_at';
        $this->hours = (int) ($options['hours'] ?? 24);
    }

    public function name(): string
    {
        return "newest {$this->table}.{$this->column} within {$this->hours}h";
    }

    public function check(RestoredDatabase $database): CheckResult
    {
        try {
            $newest = $database->db()->table($this->table)->max($this->column);
        } catch (Throwable $exception) {
            return CheckResult::fail("{$this->table}.{$this->column}: ".strtok($exception->getMessage(), "\n"));
        }

        if ($newest === null) {
            return CheckResult::fail("{$this->table} has no rows");
        }

        $at = is_numeric($newest) ? CarbonImmutable::createFromTimestamp((int) $newest) : CarbonImmutable::parse((string) $newest);
        $age = $at->diffInHours(CarbonImmutable::now(), true);

        return $age <= $this->hours
            ? CheckResult::pass("newest {$this->table}.{$this->column}: {$at->toIso8601String()}")
            : CheckResult::fail(sprintf('newest %s.%s is %dh old (%s), expected within %dh', $this->table, $this->column, (int) $age, $at->toIso8601String(), $this->hours));
    }
}
