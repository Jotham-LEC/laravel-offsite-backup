<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\DatabasesAreExplicit;
use Jothamlec\OffsiteBackup\Doctor\Checks\MailAddressesAreValid;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Doctor\Status;

function databasesCheckReading(string $backupConfigSource): DatabasesAreExplicit
{
    $path = test()->sandboxPath('config-backup.php');
    file_put_contents($path, $backupConfigSource);

    return new class($path) extends DatabasesAreExplicit
    {
        public function __construct(private readonly string $path) {}

        protected function backupConfigPath(): string
        {
            return $this->path;
        }
    };
}

it('passes for the hardened config\'s explicit connections', function () {
    $check = databasesCheckReading((string) file_get_contents($this->sandboxPath('backup.php')));

    expect($check->run(new DoctorContext))
        ->status->toBe(Status::Pass)
        ->message->toBe('Dumping app, listed explicitly.');
});

it('fails when config/backup.php takes the databases from DB_CONNECTION', function () {
    // spatie's stock config, as the hand-rolled setups published it.
    $check = databasesCheckReading("<?php return ['backup' => ['source' => [\n    'databases' => [\n        env('DB_CONNECTION', 'mysql'),\n    ],\n    'database_dump_compressor' => null,\n]]];");
    config(['backup.backup.source.databases' => ['mysql'], 'database.connections.mysql' => ['driver' => 'mysql']]);

    $result = $check->run(new DoctorContext);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->message)->toContain('takes backup.source.databases from DB_CONNECTION (now: mysql)')
        ->and($result->message)->toContain("DB_CONNECTION isn't set")
        ->and($result->message)->toContain('differs from offsite-backup.connections (app)')
        ->and($result->hint)->toContain('OFFSITE_BACKUP_CONNECTIONS');
});

it('fails on a connection missing from config/database.php', function () {
    config(['backup.backup.source.databases' => ['pgsql_typo'], 'offsite-backup.connections' => ['pgsql_typo']]);

    $result = databasesCheckReading('<?php return [];')->run(new DoctorContext);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain("'pgsql_typo', which isn't in config/database.php");
});

it('warns when no database is backed up', function () {
    config(['backup.backup.source.databases' => [], 'offsite-backup.connections' => []]);

    expect(databasesCheckReading('<?php return [];')->run(new DoctorContext)->status)->toBe(Status::Warn);
});

it('fails on a placeholder mail sender, which spatie rejects even with mail notifications off', function (bool $mailOn) {
    config([
        'backup.notifications.mail.from.address' => 'hello@{{DOMAIN}}',
        'backup.notifications.mail.to' => 'ops@example.org',
    ]);

    if (! $mailOn) {
        config(['backup.notifications.notifications' => array_map(fn () => [], (array) config('backup.notifications.notifications'))]);
    }

    $result = runCheck(MailAddressesAreValid::class);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->message)->toContain("The mail sender 'hello@{{DOMAIN}}' isn't a valid address")
        ->and($result->hint)->toContain('MAIL_FROM_ADDRESS');
})->with([true, false]);

it('fails on an invalid notification address and warns about placeholders', function () {
    config(['backup.notifications.mail.from.address' => 'backups@example.org', 'backup.notifications.mail.to' => ['ops@example.org', 'not-an-address']]);

    expect(runCheck(MailAddressesAreValid::class))->status->toBe(Status::Fail)->message->toContain("'not-an-address'");

    config(['backup.notifications.mail.to' => 'your@example.com']);

    expect(runCheck(MailAddressesAreValid::class))->status->toBe(Status::Warn)->message->toContain('placeholder your@example.com');
});

it('passes with real addresses', function () {
    config(['backup.notifications.mail.from.address' => 'backups@example.org', 'backup.notifications.mail.to' => 'ops@example.org']);

    expect(runCheck(MailAddressesAreValid::class))
        ->status->toBe(Status::Pass)
        ->message->toBe('Mail notifications from backups@example.org to ops@example.org.');
});
