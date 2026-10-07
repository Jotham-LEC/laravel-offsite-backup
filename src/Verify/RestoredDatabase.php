<?php

namespace Jothamlec\OffsiteBackup\Verify;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RestoredDatabase
{
    /**
     * @param  string  $connection  a Laravel connection name pointing at the restored data
     * @param  list<string>  $errors  restore errors that weren't ignored
     * @param  list<string>  $ignoredErrors
     */
    public function __construct(
        public readonly string $connection,
        public readonly string $driver,
        public readonly array $errors = [],
        public readonly array $ignoredErrors = [],
        public readonly ?string $integrity = null,
    ) {}

    public function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /**
     * Table names, schema-qualified outside the default schema.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach (Schema::connection($this->connection)->getTables() as $table) {
            $name = (string) $table['name'];

            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $schema = $table['schema'] ?? null;
            $tables[] = is_string($schema) && $schema !== '' && ! in_array($schema, ['public', 'main'], true) && $this->driver === 'pgsql'
                ? "{$schema}.{$name}"
                : $name;
        }

        sort($tables);

        return $tables;
    }

    /**
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        $counts = [];

        foreach ($this->tables() as $table) {
            $counts[$table] = $this->db()->table($table)->count();
        }

        return $counts;
    }
}
