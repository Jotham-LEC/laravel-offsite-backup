<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete([config_path('offsite-backup.php'), config_path('backup.php')]);
});

afterEach(function () {
    File::delete([config_path('offsite-backup.php'), config_path('backup.php')]);
});

it('publishes config/offsite-backup.php and prints the hardened config/backup.php', function () {
    $this->artisan('offsite:install')
        ->expectsOutputToContain("'encryption' => 'aes256',")
        ->expectsOutputToContain("'databases' => Preset::connections(\$offsite),")
        ->assertSuccessful();

    expect(config_path('offsite-backup.php'))->toBeFile()
        ->and(config_path('backup.php'))->not->toBeFile();
});

it('writes config/backup.php with --write, and refuses to overwrite without --force', function () {
    $this->artisan('offsite:install --write')->assertSuccessful();

    expect(file_get_contents(config_path('backup.php')))->toContain("Preset::load(__DIR__.'/offsite-backup.php')");

    file_put_contents(config_path('backup.php'), '<?php return [];');

    $this->artisan('offsite:install --write')
        ->expectsOutputToContain('config/backup.php exists')
        ->assertFailed();

    expect(file_get_contents(config_path('backup.php')))->toBe('<?php return [];');

    $this->artisan('offsite:install --write --force')->assertSuccessful();

    $written = require config_path('backup.php');
    expect($written['backup']['encryption'])->toBe('aes256');
});

it('keeps customised values when overwriting config/backup.php with --force', function () {
    $this->artisan('offsite:install --write')->assertSuccessful();

    $path = config_path('backup.php');
    file_put_contents($path, str_replace(
        ["'to' => env('BACKUP_NOTIFY_EMAIL', 'your@example.com'),", "'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),", "'encryption' => 'aes256',"],
        ["'to' => 'ops@shop.test',", "'address' => 'backups@shop.test',", "'encryption' => 'none',"],
        (string) file_get_contents($path),
    ));

    $this->artisan('offsite:install --write --force')
        ->expectsOutputToContain('Kept your config/backup.php values for: notifications.mail.to, notifications.mail.from.address.')
        ->assertSuccessful();

    $written = require $path;
    expect($written['notifications']['mail']['to'])->toBe('ops@shop.test')
        ->and($written['notifications']['mail']['from']['address'])->toBe('backups@shop.test')
        // Only those values: the hardening itself is restored.
        ->and($written['backup']['encryption'])->toBe('aes256');
});

it('keeps an existing config/offsite-backup.php', function () {
    file_put_contents(config_path('offsite-backup.php'), "<?php return ['name' => 'mine'];");

    $this->artisan('offsite:install')->expectsOutputToContain('already exists')->assertSuccessful();

    expect(file_get_contents(config_path('offsite-backup.php')))->toContain("'mine'");
});

it('prints a disk preset', function () {
    $this->artisan('offsite:install --disk=b2')
        ->expectsOutputToContain("'b2' => [")
        ->expectsOutputToContain("'request_checksum_calculation' => 'when_required',")
        ->expectsOutputToContain('OFFSITE_BACKUP_DISK=b2')
        ->expectsOutputToContain('B2_SECRET_ACCESS_KEY=')
        ->assertSuccessful();
});

it('rejects an unknown disk preset', function () {
    $this->artisan('offsite:install --disk=ftp')->assertExitCode(2);
});
