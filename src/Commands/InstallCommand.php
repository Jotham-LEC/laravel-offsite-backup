<?php

namespace Jothamlec\OffsiteBackup\Commands;

use Illuminate\Console\Command;
use Jothamlec\OffsiteBackup\Install\DiskPreset;
use Jothamlec\OffsiteBackup\Install\HardenedBackupConfig;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    protected $signature = 'offsite:install
        {--write : Write the hardened config/backup.php instead of printing it}
        {--force : Overwrite an existing config/backup.php or config/offsite-backup.php}
        {--disk= : Print a config/filesystems.php disk for b2, r2, s3 or wasabi}';

    protected $description = 'Publishes config/offsite-backup.php and a hardened config/backup.php for spatie/laravel-backup';

    public function handle(): int
    {
        $disk = $this->option('disk');

        if (is_string($disk) && ! in_array($disk, DiskPreset::names(), true)) {
            $this->components->error("Unknown --disk '{$disk}'. Use one of: ".implode(', ', DiskPreset::names()).'.');

            return self::INVALID;
        }

        $this->publishOffsiteConfig();

        try {
            $hardened = (new HardenedBackupConfig)->render();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $existing = config_path('backup.php');

        if (is_file($existing)) {
            [$hardened, $kept] = (new HardenedBackupConfig)->preserve($hardened, (string) file_get_contents($existing));

            if ($kept !== []) {
                $this->components->info('Kept your config/backup.php values for: '.implode(', ', $kept).'.');
            }
        }

        if (! $this->writeOrPrintBackupConfig($hardened)) {
            return self::FAILURE;
        }

        if (is_string($disk)) {
            $this->printDisk($disk);
        }

        $this->newLine();
        $this->line('Next:');
        $this->line('  1. Set BACKUP_ARCHIVE_PASSWORD, OFFSITE_BACKUP_CONNECTIONS and the disk\'s keys in .env (or `dep offsite:env`).');
        $this->line('  2. php artisan offsite:doctor');
        $this->line('  3. php artisan backup:run, then php artisan offsite:verify somewhere other than production.');

        return self::SUCCESS;
    }

    private function publishOffsiteConfig(): void
    {
        $target = config_path('offsite-backup.php');

        if (is_file($target) && ! $this->option('force')) {
            $this->components->info('config/offsite-backup.php already exists; kept it.');

            return;
        }

        $this->callSilently('vendor:publish', [
            '--tag' => 'offsite-backup-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->components->info('Published config/offsite-backup.php.');
    }

    private function writeOrPrintBackupConfig(string $hardened): bool
    {
        if (! $this->option('write')) {
            $this->line('<comment>// config/backup.php (run with --write to save it)</comment>');
            $this->output->writeln(explode("\n", rtrim($hardened)), OutputInterface::OUTPUT_RAW);

            return true;
        }

        $target = config_path('backup.php');

        if (is_file($target) && ! $this->option('force')) {
            $this->components->error('config/backup.php exists. Compare it with `php artisan offsite:install` or overwrite it with --write --force.');

            return false;
        }

        file_put_contents($target, $hardened);
        $this->components->info('Wrote the hardened config/backup.php.');

        return true;
    }

    private function printDisk(string $preset): void
    {
        $this->newLine();
        $this->line("<comment>// config/filesystems.php, inside 'disks' => [...]</comment>");
        $this->output->writeln(explode("\n", rtrim(DiskPreset::snippet($preset))), OutputInterface::OUTPUT_RAW);
        $this->line('<comment># .env</comment>');
        $this->line('OFFSITE_BACKUP_DISK='.DiskPreset::diskName($preset));

        foreach (DiskPreset::envKeys($preset) as $key) {
            $this->line("{$key}=");
        }
    }
}
