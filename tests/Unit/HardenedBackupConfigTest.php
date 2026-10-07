<?php

use Jothamlec\OffsiteBackup\Install\HardenedBackupConfig;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

function renderHardened(string $directory, ?array $offsite = null): array
{
    file_put_contents($directory.'/backup.php', (new HardenedBackupConfig)->render());
    if (is_file($directory.'/offsite-backup.php')) {
        unlink($directory.'/offsite-backup.php');
    }

    if ($offsite !== null) {
        file_put_contents($directory.'/offsite-backup.php', '<?php return '.var_export($offsite, true).';');
    }

    return require $directory.'/backup.php';
}

it('hardens spatie\'s own config', function () {
    $dir = $this->sandboxPath('render');
    mkdir($dir);
    $config = renderHardened($dir);
    $backup = $config['backup'];
    $strategy = $config['cleanup']['default_strategy'];
    $notifications = $config['notifications']['notifications'];

    expect($backup['encryption'])->toBe('aes256')
        ->and($backup['verify_backup'])->toBeTrue()
        ->and($backup['tries'])->toBe(3)
        ->and($backup['retry_delay'])->toBe(60)
        ->and($backup['database_dump_compressor'])->toBe(GzipCompressor::class)
        ->and($backup['password'])->toBe(env('BACKUP_ARCHIVE_PASSWORD'))
        ->and($strategy['keep_all_backups_for_days'])->toBe(7)
        ->and($strategy['keep_daily_backups_for_days'])->toBe(23)
        ->and($strategy['keep_weekly_backups_for_weeks'])->toBe(0)
        ->and($strategy['keep_monthly_backups_for_months'])->toBe(12)
        ->and($strategy['keep_yearly_backups_for_years'])->toBe(0)
        ->and($strategy['delete_oldest_backups_when_using_more_megabytes_than'])->toBeNull()
        ->and($notifications[BackupHasFailedNotification::class])->toBe(['mail'])
        ->and($notifications[BackupWasSuccessfulNotification::class])->toBe([])
        ->and($notifications[HealthyBackupWasFoundNotification::class])->toBe([])
        ->and($notifications[CleanupWasSuccessfulNotification::class])->toBe([])
        // Spatie's own tries for cleanup stay as they were.
        ->and($config['cleanup']['tries'])->toBe(1);
});

it('takes the name, disk, paths and connections from config/offsite-backup.php', function () {
    $dir = $this->sandboxPath('render');
    mkdir($dir);
    $config = renderHardened($dir, [
        'name' => 'shop',
        'disk' => 'r2',
        'shared_path' => '/srv/shop/shared',
        'include' => ['.'],
        'exclude' => ['storage/logs'],
        'connections' => ['pgsql'],
        'temporary_directory' => '/var/tmp/shop-backup',
        'monitor' => ['max_storage_mb' => 50000],
    ]);

    expect($config['backup']['name'])->toBe('shop')
        ->and($config['backup']['destination']['disks'])->toBe(['r2'])
        ->and($config['backup']['source']['files']['include'])->toBe(['/srv/shop/shared'])
        ->and($config['backup']['source']['files']['exclude'])->toContain('/srv/shop/shared/storage/logs')
        ->and($config['backup']['source']['files']['relative_path'])->toBe('/srv/shop/shared')
        ->and($config['backup']['source']['files']['follow_links'])->toBeFalse()
        ->and($config['backup']['source']['databases'])->toBe(['pgsql'])
        ->and($config['backup']['temporary_directory'])->toBe('/var/tmp/shop-backup')
        ->and($config['monitor_backups'][0]['name'])->toBe('shop')
        ->and($config['monitor_backups'][0]['disks'])->toBe(['r2'])
        ->and($config['monitor_backups'][0]['health_checks'][MaximumStorageInMegabytes::class])->toBe(50000);
});

it('never backs up DB_CONNECTION implicitly', function () {
    $dir = $this->sandboxPath('render');
    mkdir($dir);
    putenv('DB_CONNECTION=mysql');

    try {
        expect(renderHardened($dir, ['connections' => []])['backup']['source']['databases'])->toBe([]);
    } finally {
        putenv('DB_CONNECTION');
    }

    expect((new HardenedBackupConfig)->render())->not->toContain("env('DB_CONNECTION'");
});

it('fails loudly when spatie\'s config changes shape', function () {
    $source = (string) file_get_contents(HardenedBackupConfig::spatieConfigPath());
    $changed = $this->sandboxPath('spatie-backup.php');
    file_put_contents($changed, str_replace("'encryption' => 'default',", "'encryption_method' => 'default',", $source));

    (new HardenedBackupConfig($changed))->render();
})->throws(RuntimeException::class, 'expected 1 match(es) for "encryption"');

it('sorts the use imports the way Pint does', function () {
    preg_match_all('/^use ([^;]+);$/m', (new HardenedBackupConfig)->render(), $matches);
    $sorted = $matches[1];
    usort($sorted, fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

    expect($matches[1])->toContain('Jothamlec\OffsiteBackup\Support\Preset')
        ->and($matches[1])->toBe($sorted)
        ->and($matches[1][0])->toBe('Jothamlec\OffsiteBackup\Support\Preset');
});

it('carries customised values over from an existing config, keeping env() calls as written', function () {
    $hardened = new HardenedBackupConfig;
    $rendered = $hardened->render();

    $existing = str_replace(
        ["'to' => env('BACKUP_NOTIFY_EMAIL', 'your@example.com'),", "'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),", "'name' => env('MAIL_FROM_NAME', 'Example'),"],
        ["'to' => ['ops@shop.test', 'cto@shop.test'],", "'address' => 'backups@shop.test',", "'name' => env('BACKUP_FROM_NAME', 'Shop backups'),"],
        $rendered,
    );
    $existing = preg_replace("/'name' => \\\$offsite\\['name'\\],/", "'name' => 'shop-legacy',", $existing, 1);

    [$merged, $kept] = $hardened->preserve($rendered, $existing);

    expect($kept)->toBe(['backup.name', 'notifications.mail.to', 'notifications.mail.from.address', 'notifications.mail.from.name'])
        ->and($merged)->toBe($existing);
});

it('does not carry over spatie\'s or the hardened defaults', function () {
    $hardened = new HardenedBackupConfig;
    $rendered = $hardened->render();

    [$fromSpatie, $keptFromSpatie] = $hardened->preserve($rendered, (string) file_get_contents(HardenedBackupConfig::spatieConfigPath()));
    [$fromItself, $keptFromItself] = $hardened->preserve($rendered, $rendered);

    expect($keptFromSpatie)->toBe([])->and($fromSpatie)->toBe($rendered)
        ->and($keptFromItself)->toBe([])->and($fromItself)->toBe($rendered)
        ->and($hardened->preserve($rendered, '<?php return [];'))->toBe([$rendered, []]);
});
