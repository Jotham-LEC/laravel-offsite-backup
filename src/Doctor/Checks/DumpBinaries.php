<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\BinaryLocator;
use Jothamlec\OffsiteBackup\Support\DatabaseInspector;

class DumpBinaries implements Check
{
    public const BINARIES = [
        'sqlite' => 'sqlite3',
        'pgsql' => 'pg_dump',
        'mysql' => 'mysqldump',
        'mariadb' => 'mariadb-dump',
        'mongodb' => 'mongodump',
    ];

    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly DatabaseInspector $inspector,
    ) {}

    public function name(): string
    {
        return 'Dump binaries';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $connections = array_values(array_filter((array) config('backup.backup.source.databases', []), 'is_string'));

        if ($connections === []) {
            return CheckResult::warn('No database connections are backed up.', 'Set OFFSITE_BACKUP_CONNECTIONS (e.g. pgsql), unless this app has no database.');
        }

        return CheckResult::combine(array_map($this->checkConnection(...), $connections));
    }

    private function checkConnection(string $connection): CheckResult
    {
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            return CheckResult::fail("Connection '{$connection}' isn't in config/database.php.");
        }

        $driver = (string) ($config['driver'] ?? '');
        $binary = self::BINARIES[$driver] ?? null;

        if ($binary === null) {
            return CheckResult::fail("Connection '{$connection}': spatie can't dump the '{$driver}' driver.");
        }

        $directory = $config['dump']['dump_binary_path'] ?? null;
        $path = $this->binaries->find($binary, is_string($directory) ? $directory : null);

        if ($path === null) {
            return CheckResult::fail(
                "Connection '{$connection}': {$binary} not found".(is_string($directory) && $directory !== '' ? " in {$directory}" : ' on PATH').'.',
                "Install it (the scheduler's PATH may be shorter than yours), or set database.connections.{$connection}.dump.dump_binary_path.",
            );
        }

        $version = $this->binaries->version($path);

        if ($driver !== 'pgsql') {
            return CheckResult::pass("Connection '{$connection}': {$path}".($version !== null ? " ({$version})" : '').'.');
        }

        $client = BinaryLocator::majorVersion($version);
        $serverNum = $this->inspector->postgresVersionNum($connection);

        if ($serverNum === null) {
            return CheckResult::warn("Connection '{$connection}': {$path} ({$version}), but the server's version couldn't be queried.", 'Check the connection: SHOW server_version_num failed.');
        }

        $server = intdiv($serverNum, 10000);

        if ($client === null || $client < $server) {
            return CheckResult::fail(
                "Connection '{$connection}': pg_dump ".($client ?? '?')." is older than the server ({$server}); pg_dump refuses to dump newer servers.",
                "Install postgresql-client-{$server} and point dump.dump_binary_path at it (e.g. /usr/lib/postgresql/{$server}/bin).",
            );
        }

        return CheckResult::pass("Connection '{$connection}': pg_dump {$client} for server {$server}.");
    }
}
