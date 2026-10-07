<?php

use Illuminate\Support\Facades\Process;
use Jothamlec\OffsiteBackup\Support\BinaryLocator;
use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\Restorers\ServerRestorer;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

function restorerWithoutWipe(array $ignore = []): ServerRestorer
{
    $binaries = new class extends BinaryLocator
    {
        public function find(string $binary, ?string $directory = null): ?string
        {
            return '/usr/bin/'.$binary;
        }
    };

    return new class('scratch', $ignore, $binaries) extends ServerRestorer
    {
        protected function wipe(): void {}
    };
}

beforeEach(function () {
    config(['database.connections.scratch' => [
        'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5433, 'database' => 'verify', 'username' => 'verify', 'password' => 'pw',
    ]]);
    $this->dump = new DumpFile('postgresql-shop.sql', $this->sandboxPath('postgresql-shop.sql'), 'pgsql');
    file_put_contents($this->dump->sqlPath, 'SELECT 1;');
});

it('restores with psql into the scratch connection and separates ignorable errors', function () {
    Process::fake(['*' => Process::result(
        errorOutput: "psql:dump.sql:12: ERROR:  role \"shop_owner\" does not exist\npsql:dump.sql:40: ERROR:  relation \"x\" already exists\n",
    )]);

    $restored = restorerWithoutWipe(['/role "[^"]+" does not exist/'])->restore($this->dump, $this->sandboxPath());

    expect($restored->connection)->toBe('scratch')
        ->and($restored->errors)->toBe(['psql:dump.sql:40: ERROR:  relation "x" already exists'])
        ->and($restored->ignoredErrors)->toHaveCount(1);

    Process::assertRan(fn ($process) => $process->command === [
        '/usr/bin/psql', '-X', '-q', '-v', 'ON_ERROR_STOP=0', '-h', '127.0.0.1', '-p', '5433', '-U', 'verify', '-d', 'verify', '-f', $this->dump->sqlPath,
    ] && $process->environment['PGPASSWORD'] === 'pw');
});

it('refuses a dump for another driver', function () {
    $mysql = new DumpFile('mysql-shop.sql', $this->dump->sqlPath, 'mysql');

    restorerWithoutWipe()->restore($mysql, $this->sandboxPath());
})->throws(VerifyFailed::class, "is a mysql dump, but the scratch connection 'scratch' is pgsql");
