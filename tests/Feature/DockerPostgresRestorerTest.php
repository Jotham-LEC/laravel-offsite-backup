<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Jothamlec\OffsiteBackup\Support\Docker;
use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\Restorers\DockerPostgresRestorer;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

function fakeDocker(string $psqlErrors = ''): void
{
    Process::fake(['*' => function (PendingProcess $process) use ($psqlErrors) {
        $command = (array) $process->command;

        return match (array_slice($command, 0, 2)) {
            ['docker', 'run'] => Process::result("3f2a\n"),
            ['docker', 'port'] => Process::result("127.0.0.1:49321\n"),
            ['docker', 'exec'] => in_array('pg_isready', $command, true) ? Process::result() : Process::result(errorOutput: $psqlErrors),
            default => Process::result(),
        };
    }]);
}

beforeEach(function () {
    $this->sql = $this->sandboxPath('postgresql-shop.sql');
    file_put_contents($this->sql, "-- PostgreSQL database dump\n-- Dumped from database version 16.4\n-- Dumped by pg_dump version 17.2\nSET transaction_timeout = 0;\n-- PostgreSQL database dump complete\n");
});

it('restores into a throwaway postgres:<pg_dump major> container and removes it', function () {
    fakeDocker("psql:<stdin>:20: ERROR:  role \"shop\" does not exist\n");
    $restorer = new DockerPostgresRestorer;

    $restored = $restorer->restore(new DumpFile('postgresql-shop.sql', $this->sql, 'pgsql'), $this->sandboxPath());
    $config = config("database.connections.{$restored->connection}");

    expect($restored->errors)->toBe([])
        ->and($restored->ignoredErrors)->toHaveCount(1)
        ->and($restored->notes)->toBe(['restored into a throwaway postgres:17 container'])
        ->and($config['host'])->toBe('127.0.0.1')
        ->and($config['port'])->toBe(49321);

    $restorer->cleanup();

    Process::assertRan(function (PendingProcess $process) {
        $command = (array) $process->command;

        // The password travels in the environment, not on the command line.
        return array_slice($command, 0, 4) === ['docker', 'run', '-d', '--rm']
            && in_array('postgres:17', $command, true)
            && in_array('127.0.0.1::5432', $command, true)
            && in_array('POSTGRES_PASSWORD', $command, true)
            && strlen($process->environment['POSTGRES_PASSWORD']) === 32;
    });
    Process::assertRan(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['docker', 'exec', '-i']);
    Process::assertRan(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 4) === ['docker', 'rm', '-f', '-v']);
})->skip(fn () => ! extension_loaded('pdo_pgsql'), 'needs pdo_pgsql');

it('counts other restore errors', function () {
    fakeDocker("psql:<stdin>:20: ERROR:  syntax error at or near \"x\"\n");

    $restored = (new DockerPostgresRestorer)->restore(new DumpFile('postgresql-shop.sql', $this->sql, 'pgsql'), $this->sandboxPath());

    expect($restored->errors)->toBe(['psql:<stdin>:20: ERROR:  syntax error at or near "x"']);
})->skip(fn () => ! extension_loaded('pdo_pgsql'), 'needs pdo_pgsql');

it('needs the pg_dump version to pick an image', function () {
    file_put_contents($this->sql, "-- PostgreSQL database dump\n-- PostgreSQL database dump complete\n");

    (new DockerPostgresRestorer)->restore(new DumpFile('postgresql-shop.sql', $this->sql, 'pgsql'), $this->sandboxPath());
})->throws(VerifyFailed::class, 'the PostgreSQL image to restore it with is unknown');

it('is offsite:verify\'s fallback when there is no scratch connection', function () {
    app()->instance(Docker::class, new class extends Docker
    {
        public function available(): bool
        {
            return true;
        }
    });
    fakeDocker("psql:<stdin>:20: ERROR:  role \"shop\" does not exist\n");
    config(['offsite-backup.verify.docker' => true, 'offsite-backup.connections' => []]);
    $this->putBackup(['.env' => 'x', 'db-dumps/postgresql-shop.sql.gz' => gzencode((string) file_get_contents($this->sql))]);

    // No database answers on the fake container's port, so counting rows fails; the restore
    // itself, the image choice and the cleanup are what's checked here.
    Artisan::call('offsite:verify', ['--json' => true, '--no-ping' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['databases'][0]['postgres'])->toBe(['server' => 16, 'dump' => 17]);
    Process::assertRan(fn (PendingProcess $process) => in_array('postgres:17', (array) $process->command, true));
    Process::assertRan(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['docker', 'rm', '-f']);
})->skip(fn () => ! extension_loaded('pdo_pgsql'), 'needs pdo_pgsql');
