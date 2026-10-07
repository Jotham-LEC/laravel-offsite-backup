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
