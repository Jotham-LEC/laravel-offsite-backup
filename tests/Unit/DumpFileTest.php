<?php

use Jothamlec\OffsiteBackup\Verify\DumpFile;

it('detects the driver from spatie\'s file names', function (string $name, string $driver) {
    expect(DumpFile::detectDriver($name, '/nonexistent'))->toBe($driver);
})->with([
    ['sqlite-app-database.sql.gz', 'sqlite'],
    ['postgresql-shop.sql.gz', 'pgsql'],
    ['mysql-shop.sql', 'mysql'],
    ['mariadb-shop.sql.gz', 'mariadb'],
    ['mongodb-shop.archive', 'mongodb'],
]);

it('sniffs the driver from the dump header otherwise', function () {
    $path = $this->sandboxPath('custom.sql');
    file_put_contents($path, "--\n-- PostgreSQL database dump\n--\n");

    expect(DumpFile::detectDriver('custom.sql', $path))->toBe('pgsql');
});

it('decompresses gzipped dumps and spots truncation', function () {
    $dumps = $this->sandboxPath('dumps');
    mkdir($dumps);
    file_put_contents($dumps.'/postgresql-shop.sql.gz', gzencode("--\n-- PostgreSQL database dump\n--\nCREATE TABLE t();\n--\n-- PostgreSQL database dump complete\n--\n"));
    file_put_contents($dumps.'/postgresql-cut.sql.gz', gzencode("--\n-- PostgreSQL database dump\n--\nCREATE TABLE t("));

    $complete = DumpFile::fromArchive($dumps.'/postgresql-shop.sql.gz', $this->sandboxPath());
    $cut = DumpFile::fromArchive($dumps.'/postgresql-cut.sql.gz', $this->sandboxPath());

    expect($complete->sqlPath)->toEndWith('postgresql-shop.sql')
        ->and($complete->driver)->toBe('pgsql')
        ->and($complete->looksComplete())->toBeTrue()
        ->and($cut->looksComplete())->toBeFalse();
});

it('accepts a complete sqlite3 .dump', function () {
    $path = $this->sandboxPath('sqlite-app-database.sql');
    file_put_contents($path, $this->sqliteDump());

    expect((new DumpFile('sqlite-app-database.sql', $path, 'sqlite'))->looksComplete())->toBeTrue();
})->skip(fn () => trim((string) shell_exec('command -v sqlite3')) === '', 'sqlite3 CLI not installed');
