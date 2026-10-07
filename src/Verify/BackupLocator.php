<?php

namespace Jothamlec\OffsiteBackup\Verify;

use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Throwable;

class BackupLocator
{
    /**
     * The backups under {name}/ on the disk, newest first. Unlike spatie's BackupDestination,
     * a listing error is reported instead of looking like an empty bucket.
     */
    public function all(string $disk, string $name): BackupCollection
    {
        try {
            $filesystem = Storage::disk($disk);
            $files = $filesystem->allFiles($name);
        } catch (Throwable $exception) {
            throw new VerifyFailed("Can't list {$name}/ on disk '{$disk}': ".$exception->getMessage(), previous: $exception);
        }

        return BackupCollection::createFromFiles($filesystem, $files);
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
