<?php

namespace Jothamlec\OffsiteBackup\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseInspector
{
    /**
     * PostgreSQL's server_version_num (160004 for 16.4), or null when unreachable.
     */
    public function postgresVersionNum(string $connection): ?int
    {
        try {
            $row = DB::connection($connection)->selectOne('SHOW server_version_num');
            $value = is_object($row) ? (array) $row : [];

            return isset($value['server_version_num']) ? (int) $value['server_version_num'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A human-readable server version, or null when unreachable.
     */
    public function serverVersion(string $connection): ?string
    {
        try {
            $db = DB::connection($connection);

            $sql = match ($db->getDriverName()) {
                'pgsql' => 'SHOW server_version',
                'mysql', 'mariadb' => 'SELECT VERSION() AS v',
                'sqlite' => 'SELECT sqlite_version() AS v',
                default => null,
            };

            if ($sql === null) {
                return null;
            }

            $row = (array) $db->selectOne($sql);
            $value = reset($row);

            return is_scalar($value) ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The database's size in bytes (estimate), or null.
     */
    public function sizeInBytes(string $connection): ?int
    {
        try {
            $db = DB::connection($connection);
            $config = $db->getConfig();

            return match ($db->getDriverName()) {
                'sqlite' => is_string($config['database'] ?? null) && is_file($config['database']) ? (int) filesize($config['database']) : null,
                'pgsql' => (int) ((array) $db->selectOne('SELECT pg_database_size(current_database()) AS s'))['s'],
                'mysql', 'mariadb' => (int) ((array) $db->selectOne(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) AS s FROM information_schema.tables WHERE table_schema = DATABASE()'
                ))['s'],
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
