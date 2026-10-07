<?php

namespace Jothamlec\OffsiteBackup\Verify\Restorers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Jothamlec\OffsiteBackup\Support\BinaryLocator;
use Jothamlec\OffsiteBackup\Support\DatabaseInspector;
use Jothamlec\OffsiteBackup\Support\Docker;
use Jothamlec\OffsiteBackup\Support\PostgresClients;
use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

/**
 * Restores a PostgreSQL or MySQL/MariaDB dump into the scratch connection with psql or mysql.
 * The ScratchGuard has already refused a scratch connection pointing at a backed-up database.
 *
 * PostgreSQL dumps are restored with a psql at least as new as the pg_dump that made them: a
 * local one when there is one, else psql from the postgres:<dump major> Docker image.
 */
class ServerRestorer implements Restorer
{
    /**
     * @param  list<string>  $ignorePatterns  regexes for restore errors that don't count
     * @param  Docker|null  $docker  null: never fall back to psql from a Docker image
     */
    public function __construct(
        private readonly string $scratchConnection,
        private readonly array $ignorePatterns = [],
        private readonly BinaryLocator $binaries = new BinaryLocator,
        private readonly ?PostgresClients $postgresClients = null,
        private readonly ?Docker $docker = null,
        private readonly string $dockerImage = 'postgres:{major}',
        private readonly ?DatabaseInspector $inspector = null,
    ) {}

    public function restore(DumpFile $dump, string $workDirectory): RestoredDatabase
    {
        $config = (array) config("database.connections.{$this->scratchConnection}", []);
        $driver = (string) ($config['driver'] ?? '');

        if (self::family($driver) !== self::family($dump->driver)) {
            throw new VerifyFailed("{$dump->name} is a {$dump->driver} dump, but the scratch connection '{$this->scratchConnection}' is {$driver}.");
        }

        $this->wipe();

        $notes = [];
        $patterns = $this->ignorePatterns;

        if ($driver === 'pgsql') {
            $result = $this->psql($config, $dump, $notes);
            $needed = $dump->postgresMajorNeeded();
            $server = $this->scratchServerMajor();

            if ($needed !== null && $server !== null && $server < $needed) {
                $patterns[] = RestoreErrors::NEWER_DUMP_SETTINGS;
                $notes[] = "the scratch server is PostgreSQL {$server}, older than the dump's pg_dump {$needed}: settings it doesn't know are ignored";
            }
        } else {
            $result = $this->mysql($config, $dump);
        }

        ['errors' => $errors, 'ignored' => $ignored] = RestoreErrors::classify($result['stderr'], $patterns);

        if (! $result['ok'] && $errors === []) {
            $errors[] = trim($result['stderr']) ?: 'the restore command failed';
        }

        DB::purge($this->scratchConnection);

        return new RestoredDatabase($this->scratchConnection, $driver, $errors, $ignored, notes: $notes);
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

    protected function scratchServerMajor(): ?int
    {
        $num = ($this->inspector ?? new DatabaseInspector)->postgresVersionNum($this->scratchConnection);

        return $num === null ? null : intdiv($num, 10000);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $notes
     * @return array{ok: bool, stderr: string}
     */
    private function psql(array $config, DumpFile $dump, array &$notes): array
    {
        $needed = $dump->postgresMajorNeeded();
        $directory = $config['dump']['dump_binary_path'] ?? null;
        $directory = is_string($directory) ? $directory : null;
        $clients = $this->postgresClients ?? new PostgresClients($this->binaries);
        $client = $clients->find($needed, $directory);

        $connection = [
            '-h', self::host($config),
            '-p', (string) ($config['port'] ?? 5432),
            '-U', (string) ($config['username'] ?? ''),
            '-d', (string) ($config['database'] ?? ''),
        ];

        if ($client !== null) {
            $notes[] = "restored with psql {$client['major']}";
            $command = [$client['path'], '-X', '-q', '-v', 'ON_ERROR_STOP=0', ...$connection, '-f', $dump->sqlPath];

            return $this->runRestore($command, ['PGPASSWORD' => (string) ($config['password'] ?? '')]);
        }

        $newest = $clients->newestMajor($directory);

        if ($needed === null) {
            throw new VerifyFailed("psql not found; install the PostgreSQL client to restore {$dump->name} into the scratch database.");
        }

        if ($this->docker === null || ! $this->docker->available()) {
            throw new VerifyFailed(sprintf(
                '%s was made by pg_dump %d and needs psql %d or newer, but %s. Install postgresql-client-%d, or Docker to use the postgres:%d image.',
                $dump->name,
                $needed,
                $needed,
                $newest !== null ? "the newest psql found is {$newest}" : 'no psql was found',
                $needed,
                $needed,
            ));
        }

        $image = str_replace('{major}', (string) $needed, $this->dockerImage);
        $notes[] = "restored with psql from {$image} (no local psql {$needed}+)";

        return $this->runRestore(
            ['docker', 'run', '--rm', '-i', '--network', 'host', '-e', 'PGPASSWORD', $image, 'psql', '-X', '-q', '-v', 'ON_ERROR_STOP=0', ...$connection],
            ['PGPASSWORD' => (string) ($config['password'] ?? '')],
            $dump->sqlPath,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, stderr: string}
     */
    private function mysql(array $config, DumpFile $dump): array
    {
        $binary = $config['driver'] === 'mariadb' && $this->binaries->find('mariadb') !== null ? 'mariadb' : 'mysql';

        return $this->runRestore([
            $this->binary($binary, $config),
            '-h', self::host($config),
            '-P', (string) ($config['port'] ?? 3306),
            '-u', (string) ($config['username'] ?? ''),
            (string) ($config['database'] ?? ''),
        ], ['MYSQL_PWD' => (string) ($config['password'] ?? '')], $dump->sqlPath);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{ok: bool, stderr: string}
     */
    private function runRestore(array $command, array $env, ?string $inputPath = null): array
    {
        $input = null;

        if ($inputPath !== null && ($input = fopen($inputPath, 'rb')) === false) {
            throw new VerifyFailed("Can't read {$inputPath}.");
        }

        $process = Process::timeout(3600)->env($env);

        if ($input !== null) {
            $process->input($input);
        }

        $result = $process->run($command);

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

    private static function family(string $driver): string
    {
        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
