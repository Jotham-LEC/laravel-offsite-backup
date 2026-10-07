<?php

namespace Jothamlec\OffsiteBackup\Tests;

use Illuminate\Filesystem\Filesystem;
use Jothamlec\OffsiteBackup\Install\HardenedBackupConfig;
use Jothamlec\OffsiteBackup\OffsiteBackupServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use Spatie\Backup\BackupServiceProvider;

/**
 * Every test gets a sandbox: shared/ (.env, storage/app), an SQLite database with a `users`
 * table, a local disk named "backups", and the hardened config/backup.php rendered from
 * spatie's installed config and pointed at all of it.
 */
abstract class TestCase extends Orchestra
{
    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/offsite-backup-tests-'.bin2hex(random_bytes(5));
        mkdir($this->sandbox.'/shared/storage/app/public', 0777, true);
        mkdir($this->sandbox.'/shared/storage/framework', 0777, true);
        mkdir($this->sandbox.'/shared/storage/logs', 0777, true);
        mkdir($this->sandbox.'/disk', 0777, true);
        file_put_contents($this->sandbox.'/shared/.env', "APP_KEY=base64:test\n");
        file_put_contents($this->sandbox.'/shared/storage/app/public/photo.jpg', str_repeat('x', 2048));
        file_put_contents($this->sandbox.'/shared/storage/logs/laravel.log', 'log line');

        $this->createDatabase($this->sandbox.'/app.sqlite');

        file_put_contents($this->sandbox.'/offsite-backup.php', '<?php return '.var_export([
            'name' => 'offsite-test',
            'disk' => 'backups',
            'shared_path' => $this->sandbox.'/shared',
            'temporary_directory' => $this->sandbox.'/tmp',
            'connections' => ['app'],
        ], true).';');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->sandbox)) {
            // Undo the permission changes the readability tests make before deleting.
            exec('chmod -R u+rwx '.escapeshellarg($this->sandbox).' 2>/dev/null');
            (new Filesystem)->deleteDirectory($this->sandbox);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            BackupServiceProvider::class,
            OffsiteBackupServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.name', 'Offsite Test');
        $config->set('database.connections.app', [
            'driver' => 'sqlite',
            'database' => $this->sandbox.'/app.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('filesystems.disks.backups', [
            'driver' => 'local',
            'root' => $this->sandbox.'/disk',
            'throw' => true,
        ]);
        $config->set('mail.default', 'array');

        /** @var array<string, mixed> $offsite */
        $offsite = require $this->sandbox.'/offsite-backup.php';
        $config->set('offsite-backup', array_merge((array) $config->get('offsite-backup'), $offsite));
        // Deterministic restores: tests that exercise the Docker fallback turn it on and fake it.
        $config->set('offsite-backup.verify.docker', false);

        $config->set('backup', $this->hardenedBackupConfig());
        // This machine's libzip may lack AES; tests that need encryption set a password themselves.
        $config->set('backup.backup.password', null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function hardenedBackupConfig(): array
    {
        $path = $this->sandbox.'/backup.php';
        file_put_contents($path, (new HardenedBackupConfig)->render());

        /** @var array<string, mixed> $config */
        $config = require $path;

        return $config;
    }

    protected function sandboxPath(string $path = ''): string
    {
        return rtrim($this->sandbox.'/'.ltrim($path, '/'), '/');
    }

    protected function createDatabase(string $path, int $users = 3, ?string $createdAt = null): void
    {
        if (is_file($path)) {
            unlink($path);
        }

        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');

        for ($i = 1; $i <= $users; $i++) {
            $pdo->exec(sprintf("INSERT INTO users (name, created_at) VALUES ('user %d', '%s')", $i, $createdAt ?? gmdate('Y-m-d H:i:s')));
        }
    }

    /**
     * Writes a zip into the backups disk under offsite-test/.
     *
     * @param  array<string, string>  $files  name in zip => contents
     */
    protected function putBackup(array $files, string $name = '', ?string $password = null, int $method = \ZipArchive::EM_NONE): string
    {
        $name = $name ?: gmdate('Y-m-d-H-i-s').'.zip';
        $directory = $this->sandbox.'/disk/offsite-test';
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $zip = new \ZipArchive;
        $zip->open($directory.'/'.$name, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($password !== null) {
            $zip->setPassword($password);
        }

        foreach ($files as $entry => $contents) {
            $zip->addFromString($entry, $contents);

            if ($method !== \ZipArchive::EM_NONE) {
                $zip->setEncryptionName($entry, $method, $password);
            }
        }

        $zip->close();

        return 'offsite-test/'.$name;
    }

    /**
     * A plain SQL dump of the sandbox database, as spatie's Sqlite dumper (sqlite3 .dump) writes it.
     */
    protected function sqliteDump(?string $database = null): string
    {
        $database ??= $this->sandbox.'/app.sqlite';

        return (string) shell_exec('sqlite3 '.escapeshellarg($database).' .dump');
    }
}
