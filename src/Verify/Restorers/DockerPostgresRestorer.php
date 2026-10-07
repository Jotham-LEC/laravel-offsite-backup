<?php

namespace Jothamlec\OffsiteBackup\Verify\Restorers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

/**
 * Restores a PostgreSQL dump into a throwaway postgres:<dump major> container when no scratch
 * connection is configured: the server and psql are then exactly as new as the pg_dump that
 * made the dump. The container is published on 127.0.0.1 only and removed afterwards.
 */
class DockerPostgresRestorer implements CleansUp, Restorer
{
    private ?string $container = null;

    private ?string $connection = null;

    /**
     * @param  list<string>  $ignorePatterns
     */
    public function __construct(
        private readonly string $imageTemplate = 'postgres:{major}',
        private readonly array $ignorePatterns = [],
        private readonly int $readyTimeoutSeconds = 120,
    ) {}

    public function restore(DumpFile $dump, string $workDirectory): RestoredDatabase
    {
        $major = $dump->postgresMajorNeeded()
            ?? throw new VerifyFailed("{$dump->name}: no \"Dumped by pg_dump version\" header, so the PostgreSQL image to restore it with is unknown. Set OFFSITE_VERIFY_SCRATCH_CONNECTION instead.");

        if (! extension_loaded('pdo_pgsql')) {
            throw new VerifyFailed('pdo_pgsql is needed to check a PostgreSQL restore; install the PHP extension.');
        }

        $image = str_replace('{major}', (string) $major, $this->imageTemplate);
        $password = Str::random(32);
        $container = $this->container = 'offsite-verify-'.Str::lower(Str::random(10));

        $started = Process::timeout(600)->env(['POSTGRES_PASSWORD' => $password])->run([
            'docker', 'run', '-d', '--rm', '--name', $container,
            '-e', 'POSTGRES_PASSWORD', '-p', '127.0.0.1::5432', $image,
        ]);

        if (! $started->successful()) {
            $this->container = null;

            throw new VerifyFailed("docker run {$image} failed: ".trim($started->errorOutput() ?: $started->output()));
        }

        $port = $this->publishedPort();
        $this->waitUntilReady();

        $input = fopen($dump->sqlPath, 'rb');

        if ($input === false) {
            throw new VerifyFailed("Can't read {$dump->name}.");
        }

        $result = Process::timeout(3600)->input($input)->run([
            'docker', 'exec', '-i', $container,
            'psql', '-X', '-q', '-v', 'ON_ERROR_STOP=0', '-U', 'postgres', '-d', 'postgres',
        ]);

        if (is_resource($input)) {
            fclose($input);
        }

        ['errors' => $errors, 'ignored' => $ignored] = RestoreErrors::classify($result->errorOutput(), $this->ignorePatterns);

        if (! $result->successful() && $errors === []) {
            $errors[] = trim($result->errorOutput()) ?: 'psql failed in the container';
        }

        $this->connection = 'offsite_verify_'.Str::lower(Str::random(8));
        config(["database.connections.{$this->connection}" => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $port,
            'database' => 'postgres',
            'username' => 'postgres',
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'disable',
        ]]);

        return new RestoredDatabase($this->connection, 'pgsql', $errors, $ignored, notes: ["restored into a throwaway {$image} container"]);
    }

    public function cleanup(): void
    {
        if ($this->connection !== null) {
            DB::purge($this->connection);
            $this->connection = null;
        }

        if ($this->container !== null) {
            Process::timeout(60)->run(['docker', 'rm', '-f', '-v', $this->container]);
            $this->container = null;
        }
    }

    private function publishedPort(): int
    {
        $result = Process::timeout(30)->run(['docker', 'port', (string) $this->container, '5432/tcp']);

        if (! $result->successful() || ! preg_match('/:(\d+)\s*$/m', $result->output(), $m)) {
            throw new VerifyFailed("Couldn't read the published port of container {$this->container}: ".trim($result->errorOutput() ?: $result->output()));
        }

        return (int) $m[1];
    }

    /**
     * Ready means accepting TCP: the image's init phase runs a temporary server on the Unix
     * socket only, then restarts it.
     */
    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + $this->readyTimeoutSeconds;

        while (true) {
            $ready = Process::timeout(15)->run(['docker', 'exec', (string) $this->container, 'pg_isready', '-q', '-h', '127.0.0.1', '-U', 'postgres']);

            if ($ready->successful()) {
                return;
            }

            if (microtime(true) >= $deadline) {
                throw new VerifyFailed("PostgreSQL in container {$this->container} wasn't ready after {$this->readyTimeoutSeconds}s.");
            }

            usleep(500_000);
        }
    }
}
