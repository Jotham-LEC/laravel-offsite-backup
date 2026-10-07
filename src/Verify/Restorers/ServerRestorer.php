<?php

namespace Jothamlec\OffsiteBackup\Verify\Restorers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Jothamlec\OffsiteBackup\Support\BinaryLocator;
use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

/**
 * Restores a PostgreSQL or MySQL/MariaDB dump into the scratch connection with psql or mysql.
 * The ScratchGuard has already refused a scratch connection pointing at a backed-up database.
 */
class ServerRestorer implements Restorer
{
    /**
     * @param  list<string>  $ignorePatterns  regexes for restore errors that don't count
     */
    public function __construct(
        private readonly string $scratchConnection,
        private readonly array $ignorePatterns = [],
        private readonly BinaryLocator $binaries = new BinaryLocator,
    ) {}

    public function restore(DumpFile $dump, string $workDirectory): RestoredDatabase
    {
        $config = (array) config("database.connections.{$this->scratchConnection}", []);
        $driver = (string) ($config['driver'] ?? '');

        if (self::family($driver) !== self::family($dump->driver)) {
            throw new VerifyFailed("{$dump->name} is a {$dump->driver} dump, but the scratch connection '{$this->scratchConnection}' is {$driver}.");
        }

        $this->wipe();

        $result = $driver === 'pgsql' ? $this->psql($config, $dump) : $this->mysql($config, $dump);

        $errors = [];
        $ignored = [];

        foreach (preg_split('/\R/', $result['stderr']) ?: [] as $line) {
            if (! preg_match('/\bERROR\b/', $line)) {
                continue;
            }

            if ($this->ignorable($line)) {
                $ignored[] = trim($line);
            } else {
                $errors[] = trim($line);
            }
        }

        if (! $result['ok'] && $errors === []) {
            $errors[] = trim($result['stderr']) ?: 'the restore command failed';
        }

        DB::purge($this->scratchConnection);

        return new RestoredDatabase($this->scratchConnection, $driver, $errors, $ignored);
    }

    protected function wipe(): void
    {
        $schema = Schema::connection($this->scratchConnection);
        $schema->dropAllViews();
        $schema->dropAllTables();

        if (DB::connection($this->scratchConnection)->getDriverName() === 'pgsql') {
            $schema->dropAllTypes();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, stderr: string}
     */
    private function psql(array $config, DumpFile $dump): array
    {
        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => (string) ($config['password'] ?? '')])
            ->run([
                $this->binary('psql', $config),
                '-X', '-q', '-v', 'ON_ERROR_STOP=0',
                '-h', self::host($config),
                '-p', (string) ($config['port'] ?? 5432),
                '-U', (string) ($config['username'] ?? ''),
                '-d', (string) ($config['database'] ?? ''),
                '-f', $dump->sqlPath,
            ]);

        return ['ok' => $result->successful(), 'stderr' => $result->errorOutput()];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, stderr: string}
     */
    private function mysql(array $config, DumpFile $dump): array
    {
        $input = fopen($dump->sqlPath, 'rb');

        if ($input === false) {
            throw new VerifyFailed("Can't read {$dump->name}.");
        }

        $binary = $config['driver'] === 'mariadb' && $this->binaries->find('mariadb') !== null ? 'mariadb' : 'mysql';

        $result = Process::timeout(3600)
            ->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
            ->input($input)
            ->run([
                $this->binary($binary, $config),
                '-h', self::host($config),
                '-P', (string) ($config['port'] ?? 3306),
                '-u', (string) ($config['username'] ?? ''),
                (string) ($config['database'] ?? ''),
            ]);

        if (is_resource($input)) {
            fclose($input);
        }

        return ['ok' => $result->successful(), 'stderr' => $result->errorOutput()];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function binary(string $name, array $config): string
    {
        $directory = $config['dump']['dump_binary_path'] ?? null;

        return $this->binaries->find($name, is_string($directory) ? $directory : null)
            ?? throw new VerifyFailed("{$name} not found; install the database client to restore into the scratch database.");
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function host(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';

        return is_array($host) ? (string) reset($host) : (string) $host;
    }

    private function ignorable(string $line): bool
    {
        foreach ($this->ignorePatterns as $pattern) {
            if (@preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function family(string $driver): string
    {
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
