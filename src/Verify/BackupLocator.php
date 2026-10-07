<?php

namespace Jothamlec\OffsiteBackup\Verify;

use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Throwable;

class BackupLocator
{
    /**
     * The backups in {name}/ on the disk, newest first. Unlike spatie's BackupDestination, a
     * listing error is reported instead of looking like an empty bucket, and only the top level
     * is listed, keeping only *.zip: spatie lists recursively and sends a HEAD request (mimeType)
     * for every other object, which takes minutes with thousands of mirrored files in there.
     */
    public function all(string $disk, string $name): BackupCollection
    {
        try {
            $filesystem = Storage::disk($disk);
            $files = self::zips($filesystem->files($name));
        } catch (Throwable $exception) {
            throw new VerifyFailed("Can't list {$name}/ on disk '{$disk}': ".$exception->getMessage(), previous: $exception);
        }

        return BackupCollection::createFromFiles($filesystem, $files);
    }

    /**
     * @param  array<int, string>  $paths
     * @return list<string>
     */
    public static function zips(array $paths): array
    {
        return array_values(array_filter($paths, fn (string $path): bool => pathinfo($path, PATHINFO_EXTENSION) === 'zip'));
    }

    /**
     * The newest backup, or the one whose path or file name is $wanted.
     */
    public function find(string $disk, string $name, ?string $wanted = null): Backup
    {
        $backups = $this->all($disk, $name);

        if ($backups->isEmpty()) {
            throw new VerifyFailed("No backups under {$name}/ on disk '{$disk}'.");
        }

        if ($wanted === null || $wanted === '') {
            /** @var Backup $newest */
            $newest = $backups->newest();

            return $newest;
        }

        $match = $backups->first(fn (Backup $backup): bool => $backup->path() === $wanted
            || basename($backup->path()) === $wanted
            || basename($backup->path()) === basename($wanted));

        if ($match === null) {
            throw new VerifyFailed("Backup '{$wanted}' not found under {$name}/ on disk '{$disk}'.");
        }

        return $match;
    }
}
