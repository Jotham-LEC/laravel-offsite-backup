<?php

use Jothamlec\OffsiteBackup\Verify\ScratchGuard;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

$production = ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'database' => 'shop'];

it('allows a scratch database elsewhere', function () use ($production) {
    ScratchGuard::assertSafe('scratch', ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'database' => 'shop_verify'], [$production], ['pgsql']);
    ScratchGuard::assertSafe('scratch', ['driver' => 'pgsql', 'host' => 'localhost', 'port' => 5433, 'database' => 'shop'], [$production], ['pgsql']);
})->throwsNoExceptions();

it('refuses the same host, port and database', function () use ($production) {
    ScratchGuard::assertSafe('scratch', ['driver' => 'pgsql', 'host' => 'DB.internal', 'database' => 'shop'], [$production]);
})->throws(VerifyFailed::class, 'db.internal:5432/shop is a backed-up database');

it('treats localhost, 127.0.0.1 and ::1 as one host', function () {
    ScratchGuard::assertSafe(
        'scratch',
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'app'],
        [['driver' => 'mysql', 'host' => 'localhost', 'database' => 'app']],
    );
})->throws(VerifyFailed::class);

it('refuses a backed-up connection by name', function () use ($production) {
    ScratchGuard::assertSafe('pgsql', ['driver' => 'pgsql', 'host' => 'other', 'database' => 'x'], [$production], ['pgsql']);
})->throws(VerifyFailed::class, 'it is one of the backed-up connections');
