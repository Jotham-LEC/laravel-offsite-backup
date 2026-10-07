<?php

use Jothamlec\OffsiteBackup\Manifest\AddOffsiteManifest;

/**
 * @return array<string, int> entry name => encryption method
 */
function zipEntries(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $entries[$stat['name']] = $stat['encryption_method'];
    }

    $zip->close();

    return $entries;
}

function newestZip(string $sandbox): string
{
    $zips = glob($sandbox.'/disk/offsite-test/*.zip');
    sort($zips);

    return (string) end($zips);
}

beforeEach(function () {
    if (trim((string) shell_exec('command -v sqlite3')) === '') {
        $this->markTestSkipped('spatie dumps SQLite with the sqlite3 CLI');
    }
});

it('runs spatie\'s backup with the hardened config and adds offsite-manifest.json', function () {
    $this->artisan('backup:run --disable-notifications')->assertSuccessful();

    $zip = newestZip($this->sandboxPath());
    $entries = zipEntries($zip);

    expect($entries)->toHaveKeys([
        AddOffsiteManifest::FILENAME,
        'db-dumps/sqlite-app-database.sql.gz',
        '.env',
        'storage/app/public/photo.jpg',
    ])->and($entries)->not->toHaveKey('storage/logs/laravel.log');

    $extract = $this->sandboxPath('x');
    $archive = new ZipArchive;
    $archive->open($zip);
    $archive->extractTo($extract);
    $archive->close();

    $manifest = json_decode(file_get_contents($extract.'/'.AddOffsiteManifest::FILENAME), true);

    expect($manifest)->toMatchArray([
        'format' => 1,
        'package' => 'jothamlec/laravel-offsite-backup',
        'app_name' => 'Offsite Test',
        'backup_name' => 'offsite-test',
        'relative_path' => $this->sandboxPath('shared'),
        'included_roots' => ['.'],
    ])->and($manifest['created_at'])->toBeString()
        ->and($manifest['databases'][0])->toMatchArray(['connection' => 'app', 'driver' => 'sqlite', 'database' => 'app.sqlite'])
        ->and($manifest['databases'][0]['server_version'])->toMatch('/^3\.\d+/')
        ->and($manifest['databases'][0]['dump_tool'])->toMatch('/^3\.\d+/')
        ->and($manifest['skipped'])->toContain(['item' => 'storage/framework', 'reason' => 'excluded by config'])
        ->and($manifest['skipped'])->toContain(['item' => 'storage/logs', 'reason' => 'excluded by config']);
});

it('encrypts the manifest with the rest of the archive', function () {
    config(['backup.backup.password' => 'correct horse']);

    $this->artisan('backup:run --disable-notifications')->assertSuccessful();

    $entries = zipEntries(newestZip($this->sandboxPath()));

    expect($entries[AddOffsiteManifest::FILENAME])->toBe(ZipArchive::EM_AES_256)
        ->and($entries['db-dumps/sqlite-app-database.sql.gz'])->toBe(ZipArchive::EM_AES_256);
})->skip(fn () => ! aesSupported(), "this PHP's libzip has no AES");

it('records include paths that are missing and connections that are not backed up', function () {
    config([
        'backup.backup.source.files.include' => [$this->sandboxPath('shared'), $this->sandboxPath('shared/gone')],
        'offsite-backup.connections' => ['app', 'reporting'],
    ]);

    $manifest = app(AddOffsiteManifest::class)->build();

    expect($manifest['skipped'])->toContain(['item' => 'gone', 'reason' => 'include path does not exist'])
        ->and($manifest['skipped'])->toContain(['item' => 'database:reporting', 'reason' => 'in offsite-backup.connections but not in backup.source.databases']);
});
