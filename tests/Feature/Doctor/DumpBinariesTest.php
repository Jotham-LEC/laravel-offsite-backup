<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\DumpBinaries;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Jothamlec\OffsiteBackup\Support\BinaryLocator;
use Jothamlec\OffsiteBackup\Support\DatabaseInspector;

function fakeBinaries(array $versions, ?int $serverVersionNum = null): void
{
    app()->instance(BinaryLocator::class, new class($versions) extends BinaryLocator
    {
        public array $searched = [];

        public function __construct(private array $versions) {}

        public function find(string $binary, ?string $directory = null): ?string
        {
            $this->searched[] = [$binary, $directory];

            return isset($this->versions[$binary]) ? ($directory ?? '/usr/bin').'/'.$binary : null;
        }

        public function version(string $path): ?string
        {
            return $this->versions[basename($path)] ?? null;
        }
    });

    app()->instance(DatabaseInspector::class, new class($serverVersionNum) extends DatabaseInspector
    {
        public function __construct(private ?int $num) {}

        public function postgresVersionNum(string $connection): ?int
        {
            return $this->num;
        }
    });
}

beforeEach(function () {
    config(['database.connections.pgsql' => ['driver' => 'pgsql', 'host' => 'db', 'database' => 'shop', 'dump' => ['dump_binary_path' => '/usr/lib/postgresql/16/bin']]]);
});

it('passes when sqlite3 is installed', function () {
    fakeBinaries(['sqlite3' => '3.46.1 2024-08-13']);

    $result = runCheck(DumpBinaries::class);

    expect($result->status)->toBe(Status::Pass)->and($result->message)->toContain('/usr/bin/sqlite3 (3.46.1');
});

it('fails when the dump binary is missing', function () {
    fakeBinaries([]);

    $result = runCheck(DumpBinaries::class);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain('sqlite3 not found on PATH');
});

it('looks in dump_binary_path and compares pg_dump with the server', function (string $client, int $server, Status $status) {
    config(['backup.backup.source.databases' => ['pgsql']]);
    fakeBinaries(['pg_dump' => $client], $server);

    $result = runCheck(DumpBinaries::class);

    expect($result->status)->toBe($status)
        ->and(app(BinaryLocator::class)->searched[0])->toBe(['pg_dump', '/usr/lib/postgresql/16/bin']);
})->with([
    'same major' => ['pg_dump (PostgreSQL) 16.4', 160004, Status::Pass],
    'newer client' => ['pg_dump (PostgreSQL) 17.0 (Debian 17.0-1)', 160004, Status::Pass],
    'older client' => ['pg_dump (PostgreSQL) 14.11', 160004, Status::Fail],
]);

it('hints at the matching client package for an old pg_dump', function () {
    config(['backup.backup.source.databases' => ['pgsql']]);
    fakeBinaries(['pg_dump' => 'pg_dump (PostgreSQL) 14.11'], 170002);

    expect(runCheck(DumpBinaries::class)->hint)->toContain('postgresql-client-17');
});

it('warns when the server version can\'t be queried', function () {
    config(['backup.backup.source.databases' => ['pgsql']]);
    fakeBinaries(['pg_dump' => 'pg_dump (PostgreSQL) 16.4'], null);

    expect(runCheck(DumpBinaries::class)->status)->toBe(Status::Warn);
});

it('warns when no connections are backed up', function () {
    config(['backup.backup.source.databases' => []]);

    expect(runCheck(DumpBinaries::class)->status)->toBe(Status::Warn);
});

it('fails for an unknown connection', function () {
    config(['backup.backup.source.databases' => ['nope']]);

    expect(runCheck(DumpBinaries::class)->message)->toContain("Connection 'nope' isn't in config/database.php");
});

it('parses major versions', function () {
    expect(BinaryLocator::majorVersion('pg_dump (PostgreSQL) 16.4'))->toBe(16)
        ->and(BinaryLocator::majorVersion('mysqldump  Ver 8.0.36 for Linux'))->toBe(8)
        ->and(BinaryLocator::majorVersion(null))->toBeNull();
});

it('reads a real binary version through Process', function () {
    Process::fake(['*' => Process::result("pg_dump (PostgreSQL) 16.4\n")]);

    expect((new BinaryLocator)->version('/usr/bin/pg_dump'))->toBe('pg_dump (PostgreSQL) 16.4');

    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/pg_dump', '--version']);
});
