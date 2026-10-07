<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Jothamlec\OffsiteBackup\Support\BinaryLocator;
use Jothamlec\OffsiteBackup\Support\DatabaseInspector;
use Jothamlec\OffsiteBackup\Support\Docker;
use Jothamlec\OffsiteBackup\Support\PostgresClients;
use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\Restorers\ServerRestorer;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

function pgDump(string $path, ?int $server = 16, ?int $pgDump = 17): DumpFile
{
    file_put_contents($path, "--\n-- PostgreSQL database dump\n--\n\n"
        .($server !== null ? "-- Dumped from database version {$server}.4 (Debian {$server}.4-1.pgdg120+2)\n" : '')
        .($pgDump !== null ? "-- Dumped by pg_dump version {$pgDump}.2\n" : '')
        ."\nSET transaction_timeout = 0;\nSELECT 1;\n--\n-- PostgreSQL database dump complete\n--\n");

    return new DumpFile(basename($path), $path, 'pgsql');
}

/**
 * A ServerRestorer that doesn't touch a real database: psql clients, Docker and the scratch
 * server's version are stand-ins.
 *
 * @param  array{path: string, major: int}|null  $client
 */
function restorer(?array $client = ['path' => '/usr/lib/postgresql/17/bin/psql', 'major' => 17], ?int $serverMajor = 17, ?bool $docker = null, array $ignore = []): ServerRestorer
{
    $binaries = new class extends BinaryLocator
    {
        public function find(string $binary, ?string $directory = null): ?string
        {
            return '/usr/bin/'.$binary;
        }
    };

    $clients = new class($client) extends PostgresClients
    {
        public function __construct(private readonly ?array $client) {}

        public function find(?int $minimumMajor, ?string $directory = null): ?array
        {
            return $this->client !== null && ($minimumMajor === null || $this->client['major'] >= $minimumMajor) ? $this->client : null;
        }

        public function newestMajor(?string $directory = null): ?int
        {
            return 16;
        }
    };

    $inspector = new class($serverMajor) extends DatabaseInspector
    {
        public function __construct(private readonly ?int $major) {}

        public function postgresVersionNum(string $connection): ?int
        {
            return $this->major === null ? null : $this->major * 10000 + 4;
        }
    };

    $dockerStub = $docker === null ? null : new class($docker) extends Docker
    {
        public function __construct(private readonly bool $up) {}

        public function available(): bool
        {
            return $this->up;
        }
    };

    return new class('scratch', $ignore, $binaries, $clients, $dockerStub, 'postgres:{major}', $inspector) extends ServerRestorer
    {
        protected function wipe(): void {}
    };
}

beforeEach(function () {
    config(['database.connections.scratch' => [
        'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5433, 'database' => 'verify', 'username' => 'verify', 'password' => 'pw',
    ]]);
    $this->dump = pgDump($this->sandboxPath('postgresql-shop.sql'));
});

it('reads the server and pg_dump major versions from the dump header', function () {
    expect($this->dump->postgresVersions())->toBe(['server' => 16, 'dump' => 17])
        ->and($this->dump->postgresMajorNeeded())->toBe(17)
        ->and(pgDump($this->sandboxPath('old.sql'), 15, null)->postgresMajorNeeded())->toBe(15)
        ->and(pgDump($this->sandboxPath('none.sql'), null, null)->postgresMajorNeeded())->toBeNull();
});

it('restores with a psql at least as new as the dump\'s pg_dump and separates ignorable errors', function () {
    Process::fake(['*' => Process::result(
        errorOutput: "psql:dump.sql:12: ERROR:  role \"shop_owner\" does not exist\npsql:dump.sql:14: ERROR:  role \"shop_owner\" does not exist\npsql:dump.sql:40: ERROR:  relation \"x\" already exists\n",
    )]);

    $restored = restorer()->restore($this->dump, $this->sandboxPath());

    expect($restored->connection)->toBe('scratch')
        ->and($restored->errors)->toBe(['psql:dump.sql:40: ERROR:  relation "x" already exists'])
        ->and($restored->ignoredErrors)->toHaveCount(2)
        ->and($restored->notes)->toBe(['restored with psql 17']);

    Process::assertRan(fn ($process) => $process->command === [
        '/usr/lib/postgresql/17/bin/psql', '-X', '-q', '-v', 'ON_ERROR_STOP=0', '-h', '127.0.0.1', '-p', '5433', '-U', 'verify', '-d', 'verify', '-f', $this->dump->sqlPath,
    ] && $process->environment['PGPASSWORD'] === 'pw');
});

it('ignores settings a newer pg_dump wrote when the scratch server is older, and says so', function () {
    Process::fake(['*' => Process::result(errorOutput: "psql:dump.sql:5: ERROR:  unrecognized configuration parameter \"transaction_timeout\"\n")]);

    $older = restorer(serverMajor: 16)->restore($this->dump, $this->sandboxPath());
    $same = restorer(serverMajor: 17)->restore($this->dump, $this->sandboxPath());

    expect($older->errors)->toBe([])
        ->and($older->ignoredErrors)->toHaveCount(1)
        ->and($older->notes[1])->toContain('PostgreSQL 16, older than the dump\'s pg_dump 17')
        ->and($same->errors)->toHaveCount(1);
});

it('falls back to psql from the postgres:<dump major> image when no local psql is new enough', function () {
    Process::fake(['*' => Process::result()]);

    $restored = restorer(client: ['path' => '/usr/bin/psql', 'major' => 16], docker: true)->restore($this->dump, $this->sandboxPath());

    expect($restored->notes[0])->toBe('restored with psql from postgres:17 (no local psql 17+)');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'docker', 'run', '--rm', '-i', '--network', 'host', '-e', 'PGPASSWORD', 'postgres:17',
        'psql', '-X', '-q', '-v', 'ON_ERROR_STOP=0', '-h', '127.0.0.1', '-p', '5433', '-U', 'verify', '-d', 'verify',
    ] && $process->environment['PGPASSWORD'] === 'pw' && $process->input !== null);
});

it('explains which psql is needed when there is neither a new enough one nor Docker', function (?bool $docker) {
    Process::fake();

    restorer(client: ['path' => '/usr/bin/psql', 'major' => 16], docker: $docker)->restore($this->dump, $this->sandboxPath());
})->with([[null], [false]])->throws(VerifyFailed::class, 'was made by pg_dump 17 and needs psql 17 or newer, but the newest psql found is 16. Install postgresql-client-17, or Docker to use the postgres:17 image.');

it('refuses a dump for another driver', function () {
    $mysql = new DumpFile('mysql-shop.sql', $this->dump->sqlPath, 'mysql');

    restorer()->restore($mysql, $this->sandboxPath());
})->throws(VerifyFailed::class, "is a mysql dump, but the scratch connection 'scratch' is pgsql");

it('finds the oldest psql that is new enough among versioned installs', function () {
    foreach ([15, 16, 17] as $major) {
        mkdir($this->sandboxPath("pg/{$major}/bin"), 0777, true);
        file_put_contents($path = $this->sandboxPath("pg/{$major}/bin/psql"), "#!/bin/sh\n");
        chmod($path, 0755);
    }

    $binaries = new class extends BinaryLocator
    {
        public function find(string $binary, ?string $directory = null): ?string
        {
            return null;
        }

        public function version(string $path): ?string
        {
            return preg_match('#/pg/(\d+)/#', $path, $m) ? "psql (PostgreSQL) {$m[1]}.3" : null;
        }
    };

    $clients = new PostgresClients($binaries, [$this->sandboxPath('pg/*/bin/psql')]);

    expect($clients->find(16))->toBe(['path' => $this->sandboxPath('pg/16/bin/psql'), 'major' => 16])
        ->and($clients->find(17)['major'])->toBe(17)
        ->and($clients->find(18))->toBeNull()
        ->and($clients->find(null)['major'])->toBe(15)
        ->and($clients->newestMajor())->toBe(17);
});
