<?php

namespace Jothamlec\OffsiteBackup\Manifest;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Jothamlec\OffsiteBackup\Doctor\Checks\DumpBinaries;
use Jothamlec\OffsiteBackup\Doctor\Checks\PathLayout;
use Jothamlec\OffsiteBackup\Support\BinaryLocator;
use Jothamlec\OffsiteBackup\Support\DatabaseInspector;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Throwable;

/**
 * Adds offsite-manifest.json to the archive.
 *
 * spatie fires BackupManifestWasCreated after dumping the databases and listing the files, before
 * building the zip. The manifest file is written next to spatie's manifest.txt in the temporary
 * directory (the zip's directory), so it lands at the archive's root, and Zip encrypts it with
 * everything else.
 */
class AddOffsiteManifest
{
    public const FILENAME = 'offsite-manifest.json';

    public function __construct(
        private readonly DatabaseInspector $inspector,
        private readonly BinaryLocator $binaries,
    ) {}

    public function handle(BackupManifestWasCreated $event): void
    {
        try {
            $path = dirname($event->manifest->path()).'/'.self::FILENAME;
            file_put_contents($path, json_encode($this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            // The backup matters more than its metadata.
            report($exception);

            return;
        }

        $event->manifest->addFiles($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        /** @var array<string, mixed> $files */
        $files = (array) config('backup.backup.source.files', []);
        $relative = is_string($files['relative_path'] ?? null) ? $files['relative_path'] : null;
        $include = array_values(array_filter((array) ($files['include'] ?? []), 'is_string'));
        $exclude = array_values(array_filter((array) ($files['exclude'] ?? []), 'is_string'));
        $databases = array_values(array_filter((array) config('backup.backup.source.databases', []), 'is_string'));

        return [
            'format' => 1,
            'package' => 'jothamlec/laravel-offsite-backup',
            'package_version' => self::packageVersion('jothamlec/laravel-offsite-backup'),
            'spatie_version' => self::packageVersion('spatie/laravel-backup'),
            'app_name' => config('app.name'),
            'backup_name' => config('backup.backup.name'),
            'environment' => app()->environment(),
            'hostname' => gethostname() ?: null,
            'created_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'php_version' => PHP_VERSION,
            'relative_path' => $relative,
            'included_roots' => array_map(fn (string $path): string => self::relativeTo($path, $relative), $include),
            'databases' => array_map($this->describeConnection(...), $databases),
            'skipped' => $this->skipped($include, $exclude, $databases, $relative),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeConnection(string $connection): array
    {
        $config = (array) config("database.connections.{$connection}", []);
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : null;
        $dumpBinary = $driver !== null ? (DumpBinaries::BINARIES[$driver] ?? null) : null;
        $dumpVersion = null;

        if ($dumpBinary !== null) {
            $directory = $config['dump']['dump_binary_path'] ?? null;
            $path = $this->binaries->find($dumpBinary, is_string($directory) ? $directory : null);
            $dumpVersion = $path !== null ? $this->binaries->version($path) : null;
        }

        return [
            'connection' => $connection,
            'driver' => $driver,
            'host' => $driver === 'sqlite' ? null : ($config['host'] ?? null),
            'port' => $driver === 'sqlite' ? null : ($config['port'] ?? null),
            'database' => $driver === 'sqlite' ? basename((string) ($config['database'] ?? '')) : ($config['database'] ?? null),
            'server_version' => $this->inspector->serverVersion($connection),
            'dump_tool' => $dumpVersion,
        ];
    }

    /**
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     * @param  list<string>  $databases
     * @return list<array{item: string, reason: string}>
     */
    private function skipped(array $include, array $exclude, array $databases, ?string $relative): array
    {
        $skipped = [];

        foreach ($include as $path) {
            if (! file_exists($path)) {
                $skipped[] = ['item' => self::relativeTo($path, $relative), 'reason' => 'include path does not exist'];
            }
        }

        foreach ($exclude as $path) {
            if (file_exists($path)) {
                $skipped[] = ['item' => self::relativeTo($path, $relative), 'reason' => 'excluded by config'];
            }
        }

        foreach (array_filter((array) config('offsite-backup.connections', []), 'is_string') as $connection) {
            if (! in_array($connection, $databases, true)) {
                $skipped[] = ['item' => "database:{$connection}", 'reason' => 'in offsite-backup.connections but not in backup.source.databases'];
            }
        }

        if (! config('backup.backup.source.files.follow_links', false)) {
            foreach ($include as $path) {
                foreach (@scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..' && is_link($path.'/'.$entry) && is_dir($path.'/'.$entry)) {
                        $skipped[] = ['item' => self::relativeTo($path.'/'.$entry, $relative), 'reason' => 'symlinked directory (follow_links is false)'];
                    }
                }
            }
        }

        return $skipped;
    }

    private static function relativeTo(string $path, ?string $root): string
    {
        if ($root === null || $root === '') {
            return $path;
        }

        $real = PathLayout::real($path);
        $root = PathLayout::real($root);

        if ($real === $root) {
            return '.';
        }

        return str_starts_with($real, $root.'/') ? substr($real, strlen($root) + 1) : $path;
    }

    private static function packageVersion(string $package): ?string
    {
        try {
            return InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
