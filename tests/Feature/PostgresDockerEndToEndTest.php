<?php

use Jothamlec\OffsiteBackup\Verify\HealthChecks\TableHasRows;
use Symfony\Component\Process\Process;

/*
 * Real PostgreSQL restores through Docker: a pg_dump 17 dump of a PostgreSQL 16 server (which
 * contains `SET transaction_timeout`, a setting 16 rejects). Opt-in, because it pulls images:
 *
 *   OFFSITE_TEST_DOCKER=1 vendor/bin/pest tests/Feature/PostgresDockerEndToEndTest.php
 *
 * CI runs it in one matrix cell. It is not registered at all otherwise, so --fail-on-skipped
 * holds everywhere.
 */
if (getenv('OFFSITE_TEST_DOCKER') !== '1') {
    return;
}

function docker(array $arguments, array $env = [], ?string $input = null, int $timeout = 600): string
{
    $process = new Process(['docker', ...$arguments], null, $env, $input, $timeout);
    $process->mustRun();

    return $process->getOutput();
}

beforeEach(function () {
    $this->password = 'e2e-'.bin2hex(random_bytes(6));
    $this->container = 'offsite-e2e-'.bin2hex(random_bytes(4));
    docker(['run', '-d', '--rm', '--name', $this->container, '-e', 'POSTGRES_PASSWORD', '-p', '127.0.0.1::5432', 'postgres:16'], ['POSTGRES_PASSWORD' => $this->password]);
    preg_match('/:(\d+)\s*$/m', docker(['port', $this->container, '5432/tcp']), $m);
    $this->port = (int) $m[1];

    for ($i = 0; $i < 120; $i++) {
        $ready = new Process(['docker', 'exec', $this->container, 'pg_isready', '-q', '-h', '127.0.0.1', '-U', 'postgres']);

        if ($ready->run() === 0) {
            break;
        }

        usleep(500_000);
    }

    docker(['exec', '-i', $this->container, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'postgres', '-d', 'postgres'], input: <<<'SQL'
        CREATE ROLE shop_owner;
        CREATE TABLE users (id serial PRIMARY KEY, name text, created_at timestamptz DEFAULT now());
        ALTER TABLE users OWNER TO shop_owner;
        INSERT INTO users (name) SELECT 'user ' || g FROM generate_series(1, 5) g;
        CREATE DATABASE scratch;
        SQL);

    $dump = docker(['run', '--rm', '--network', 'host', '-e', 'PGPASSWORD', 'postgres:17', 'pg_dump', '-h', '127.0.0.1', '-p', (string) $this->port, '-U', 'postgres', 'postgres'], ['PGPASSWORD' => $this->password]);

    expect($dump)->toContain('Dumped from database version 16.')
        ->and($dump)->toContain('Dumped by pg_dump version 17.')
        ->and($dump)->toContain('transaction_timeout');

    $this->putBackup(['.env' => 'APP_KEY=x', 'db-dumps/postgresql-postgres.sql.gz' => gzencode($dump)]);
    config([
        'offsite-backup.connections' => [],
        'offsite-backup.verify.docker' => true,
        'offsite-backup.verify.health_checks' => [[TableHasRows::class, ['table' => 'users', 'min' => 5]]],
    ]);
});

afterEach(function () {
    (new Process(['docker', 'rm', '-f', '-v', $this->container]))->run();
});

it('restores into a throwaway postgres:17 container without a scratch connection', function () {
    Artisan::call('offsite:verify', ['--json' => true, '--no-ping' => true]);
    $report = json_decode(Artisan::output(), true);
    $step = collect($report['steps'])->firstWhere('step', 'Database postgresql-postgres.sql.gz');

    expect($step['status'])->toBe('PASS', $step['detail'])
        ->and($step['detail'])->toContain('throwaway postgres:17 container')
        ->and($step['detail'])->toContain('role "shop_owner" does not exist')
        ->and($report['databases'][0]['tables'])->toBe(['users' => 5])
        ->and($report['databases'][0]['postgres'])->toBe(['server' => 16, 'dump' => 17]);
});

it('restores into an older scratch server with a new enough psql', function () {
    config([
        'database.connections.scratch' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $this->port, 'database' => 'scratch',
            'username' => 'postgres', 'password' => $this->password, 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable',
        ],
        'offsite-backup.verify.scratch_connection' => 'scratch',
    ]);

    Artisan::call('offsite:verify', ['--json' => true, '--no-ping' => true]);
    $report = json_decode(Artisan::output(), true);
    $step = collect($report['steps'])->firstWhere('step', 'Database postgresql-postgres.sql.gz');

    expect($step['status'])->toBe('PASS', $step['detail'])
        ->and($step['detail'])->toContain('older than the dump\'s pg_dump 17')
        ->and($step['detail'])->toContain('unrecognized configuration parameter "transaction_timeout"')
        ->and($report['databases'][0]['tables'])->toBe(['users' => 5]);
});
