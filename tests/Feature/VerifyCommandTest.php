<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Jothamlec\OffsiteBackup\Support\ZipCapabilities;
use Jothamlec\OffsiteBackup\Verify\HealthChecks\NewestRowWithin;
use Jothamlec\OffsiteBackup\Verify\HealthChecks\TableHasRows;

function verifyJson(array $options = []): array
{
    Artisan::call('offsite:verify', ['--json' => true, '--no-ping' => true, ...$options]);

    return json_decode(Artisan::output(), true);
}

function stepsByName(array $report): array
{
    return array_column($report['steps'], null, 'step');
}

function needsSqlite3(): bool
{
    return trim((string) shell_exec('command -v sqlite3')) === '';
}

it('downloads, extracts and restores a real spatie backup', function () {
    $this->artisan('backup:run --disable-notifications')->assertSuccessful();

    $report = verifyJson();
    $steps = stepsByName($report);
    $database = $report['databases'][0];

    expect($report['status'])->toBe('WARN') // passes, but the test archive is unencrypted
        ->and($steps['Backup']['status'])->toBe('PASS')
        ->and($steps['Download']['status'])->toBe('PASS')
        ->and($steps['Decrypt']['detail'])->toContain('NOT encrypted')
        ->and($steps['Manifest']['status'])->toBe('PASS')
        ->and($steps['Files']['status'])->toBe('PASS')
        ->and($steps['Database sqlite-app-database.sql.gz']['status'])->toBe('PASS')
        ->and($database['driver'])->toBe('sqlite')
        ->and($database['integrity'])->toBe('ok')
        ->and($database['tables'])->toBe(['posts' => 0, 'users' => 3])
        ->and($report['backup']['files'])->toBe(2)
        ->and($report['manifest']['backup_name'])->toBe('offsite-test');

    $this->artisan('offsite:verify --no-ping')->expectsOutputToContain('Backup verified.')->assertSuccessful();
})->skip(fn () => needsSqlite3(), 'needs the sqlite3 CLI');

it('verifies an AES-256 backup end to end', function () {
    config(['backup.backup.password' => 'correct horse']);
    $this->artisan('backup:run --disable-notifications')->assertSuccessful();

    $report = verifyJson();

    expect($report['status'])->toBe('PASS')
        ->and(stepsByName($report)['Decrypt']['detail'])->toContain('AES-encrypted');
})->skip(fn () => needsSqlite3() || ! aesSupported(), 'needs sqlite3 and AES in libzip');

it('fails clearly when this libzip can\'t decrypt AES', function () {
    app()->instance(ZipCapabilities::class, new class extends ZipCapabilities
    {
        public function canDecryptAes(): bool
        {
            return false;
        }
    });
    mkdir($this->sandboxPath('disk/offsite-test'), 0777, true);
    copy(__DIR__.'/../fixtures/2026-01-01-03-00-00.zip', $this->sandboxPath('disk/offsite-test/2026-01-01-03-00-00.zip'));
    config(['backup.backup.password' => 'secret']);

    $report = verifyJson(['--backup' => '2026-01-01-03-00-00.zip']);

    expect($report['status'])->toBe('FAIL')
        ->and(stepsByName($report)['Error']['detail'])->toContain("can't decrypt AES");
});

it('opens the AES fixture made by 7-Zip', function () {
    mkdir($this->sandboxPath('disk/offsite-test'), 0777, true);
    copy(__DIR__.'/../fixtures/2026-01-01-03-00-00.zip', $this->sandboxPath('disk/offsite-test/2026-01-01-03-00-00.zip'));
    config(['backup.backup.password' => 'secret', 'offsite-backup.connections' => []]);

    $report = verifyJson(['--backup' => '2026-01-01-03-00-00.zip']);

    expect(stepsByName($report)['Decrypt']['status'])->toBe('PASS')
        ->and(stepsByName($report)['Files']['status'])->toBe('PASS');
})->skip(fn () => ! aesSupported(), "this PHP's libzip has no AES");

it('fails on a wrong password', function () {
    $this->putBackup(['.env' => 'APP_KEY=x', 'storage/app/a.txt' => 'a'], password: 'right', method: ZipArchive::EM_TRAD_PKWARE);
    config(['backup.backup.password' => 'wrong', 'offsite-backup.connections' => []]);

    $report = verifyJson();

    expect($report['status'])->toBe('FAIL')
        ->and(stepsByName($report)['Error']['detail'])->toContain('wrong archive password');
});

it('fails on an encrypted archive without a password', function () {
    $this->putBackup(['.env' => 'APP_KEY=x'], password: 'right', method: ZipArchive::EM_TRAD_PKWARE);

    expect(stepsByName(verifyJson())['Error']['detail'])->toContain('no password is set');
});

it('fails when there are no backups', function () {
    $report = verifyJson();

    expect($report['status'])->toBe('FAIL')
        ->and(stepsByName($report)['Error']['detail'])->toBe("No backups under offsite-test/ on disk 'backups'.");

    $this->artisan('offsite:verify --no-ping')->assertFailed();
});

it('fails when the newest backup is too old, but not for an explicit --backup', function () {
    $name = now()->subHours(30)->format('Y-m-d-H-i-s').'.zip';
    $this->putBackup(['.env' => 'APP_KEY=x'], name: $name);
    config(['offsite-backup.connections' => []]);

    expect(stepsByName(verifyJson())['Backup']['detail'])->toContain('backups have stopped')
        ->and(stepsByName(verifyJson(['--backup' => $name]))['Backup']['status'])->toBe('PASS');
});

it('fails for an unknown --backup', function () {
    $this->putBackup(['.env' => 'x']);

    expect(stepsByName(verifyJson(['--backup' => 'nope.zip']))['Error']['detail'])->toContain("Backup 'nope.zip' not found");
});

it('checks the expected paths and the file count', function () {
    $this->putBackup(['storage/app/a.txt' => 'a']);
    config(['offsite-backup.connections' => [], 'offsite-backup.include' => ['.', 'storage/app'], 'offsite-backup.verify.minimum_files' => 2]);

    $files = stepsByName(verifyJson())['Files'];

    expect($files['status'])->toBe('FAIL')
        ->and($files['detail'])->toContain('Missing from the archive: .env.')
        ->and($files['detail'])->toContain('1 files, expected at least 2.');
});

it('fails when connections are configured but the archive has no dumps', function () {
    $this->putBackup(['.env' => 'x']);

    expect(stepsByName(verifyJson())['Databases']['status'])->toBe('FAIL');
});

it('fails on a truncated dump', function () {
    $dump = $this->sqliteDump();
    $this->putBackup(['.env' => 'x', 'db-dumps/sqlite-app-database.sql.gz' => gzencode(substr($dump, 0, (int) (strlen($dump) / 2)))]);

    expect(stepsByName(verifyJson())['Database sqlite-app-database.sql.gz']['detail'])->toContain('is truncated');
})->skip(fn () => needsSqlite3(), 'needs the sqlite3 CLI');

it('runs the configured health checks', function () {
    $this->createDatabase($this->sandboxPath('old.sqlite'), users: 2, createdAt: '2020-01-01 00:00:00');
    $this->putBackup([
        '.env' => 'x',
        'db-dumps/sqlite-app-database.sql.gz' => gzencode($this->sqliteDump()),
        'db-dumps/sqlite-old-database.sql.gz' => gzencode($this->sqliteDump($this->sandboxPath('old.sqlite'))),
    ]);
    config(['offsite-backup.verify.health_checks' => [
        [TableHasRows::class, ['table' => 'users', 'min' => 3]],
        [NewestRowWithin::class, ['table' => 'users', 'hours' => 48, 'dump' => 'app-database']],
    ]]);

    $report = verifyJson();
    $steps = stepsByName($report);

    expect($steps['Database sqlite-app-database.sql.gz']['status'])->toBe('PASS')
        ->and($steps['Database sqlite-old-database.sql.gz']['status'])->toBe('FAIL')
        ->and($steps['Database sqlite-old-database.sql.gz']['detail'])->toContain('users: 2 rows, expected at least 3')
        ->and(array_column($report['databases'][0]['health_checks'], 'check'))->toBe([
            'at least 1 tables',
            'users has at least 3 rows',
            'newest users.created_at within 48h',
        ])
        // The dump filter kept the freshness check off the old database.
        ->and($report['databases'][1]['health_checks'])->toHaveCount(2);
})->skip(fn () => needsSqlite3(), 'needs the sqlite3 CLI');

it('needs a scratch connection for server dumps', function () {
    $this->putBackup(['.env' => 'x', 'db-dumps/postgresql-shop.sql.gz' => gzencode("-- PostgreSQL database dump\n-- PostgreSQL database dump complete\n")]);

    expect(stepsByName(verifyJson())['Database postgresql-shop.sql.gz']['detail'])->toContain('needs a scratch connection');
});

it('refuses a scratch connection that points at a backed-up database', function () {
    config([
        'database.connections.pgsql' => ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'database' => 'shop'],
        'database.connections.scratch' => ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'database' => 'shop'],
        'offsite-backup.connections' => ['pgsql'],
        'offsite-backup.verify.scratch_connection' => 'scratch',
    ]);
    $this->putBackup(['.env' => 'x', 'db-dumps/postgresql-shop.sql.gz' => gzencode("-- PostgreSQL database dump\n-- PostgreSQL database dump complete\n")]);

    expect(stepsByName(verifyJson())['Database postgresql-shop.sql.gz']['detail'])
        ->toContain("Refusing to restore into 'scratch': db.internal:5432/shop is a backed-up database.");
});

it('refuses a scratch database recorded in the archive\'s manifest', function () {
    config([
        'database.connections.scratch' => ['driver' => 'pgsql', 'host' => '10.0.0.5', 'database' => 'shop'],
        'offsite-backup.connections' => [],
        'offsite-backup.verify.scratch_connection' => 'scratch',
    ]);
    $this->putBackup([
        '.env' => 'x',
        'offsite-manifest.json' => json_encode(['databases' => [['connection' => 'pgsql', 'driver' => 'pgsql', 'host' => '10.0.0.5', 'port' => 5432, 'database' => 'shop']]]),
        'db-dumps/postgresql-shop.sql.gz' => gzencode("-- PostgreSQL database dump\n-- PostgreSQL database dump complete\n"),
    ]);

    expect(stepsByName(verifyJson())['Database postgresql-shop.sql.gz']['detail'])->toContain('10.0.0.5:5432/shop is a backed-up database');
});

it('pings the verify heartbeat', function () {
    Http::fake();
    config(['offsite-backup.verify.heartbeat' => ['url' => 'https://hc-ping.com/verify-uuid', 'format' => 'healthchecks']]);

    $this->artisan('offsite:verify')->assertFailed();

    Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/verify-uuid/fail');
});

it('does not ping with --no-ping', function () {
    Http::fake();
    config(['offsite-backup.verify.heartbeat' => ['url' => 'https://hc-ping.com/verify-uuid', 'format' => 'healthchecks']]);

    $this->artisan('offsite:verify --no-ping')->assertFailed();

    Http::assertNothingSent();
});

it('keeps the extracted files with --keep', function () {
    $this->putBackup(['.env' => 'APP_KEY=kept']);
    config(['offsite-backup.connections' => []]);

    $report = verifyJson(['--keep' => true]);

    expect($report['kept_at'])->toBeString()
        ->and(file_get_contents($report['kept_at'].'/extracted/.env'))->toBe('APP_KEY=kept');

    File::deleteDirectory($report['kept_at']);
});

it('verifies another app\'s backups with --name and --disk', function () {
    config(['filesystems.disks.other' => ['driver' => 'local', 'root' => $this->sandboxPath('other-disk'), 'throw' => true]]);

    expect(stepsByName(verifyJson(['--name' => 'shop', '--disk' => 'other']))['Error']['detail'])
        ->toBe("No backups under shop/ on disk 'other'.");
});
