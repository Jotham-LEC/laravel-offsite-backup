<?php

namespace Jothamlec\OffsiteBackup\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use RuntimeException;
use Throwable;

/**
 * Copies a Flysystem disk (a media bucket on R2 or S3, say) to the backup disk under
 * `<destination>/<path>` (default `<name>-media/`, a sibling of the backup folder). Only objects
 * missing from the backup or differing in size are copied, one stream at a time. It never
 * deletes: an object removed from the source stays in the backup, and an Object Lock bucket
 * would refuse the delete anyway.
 *
 * The destination stays outside `<name>/` because spatie lists `<name>/` recursively and asks the
 * disk for the MIME type of every object without a .zip extension (BackupCollection::createFromFiles
 * and File::isZipFile): a HEAD request per mirrored object in backup:list, clean and monitor.
 *
 * --relocate-from moves objects mirrored elsewhere on the backup disk (v0.2.0 and v0.2.1 wrote
 * `<name>/media/`) to the destination: a server-side copy, a size check, then a delete of the old
 * key (on a B2 Object Lock bucket that only hides the old version).
 */
class MirrorCommand extends Command
{
    /** The folder v0.2.0 and v0.2.1 mirrored into, under <name>/. */
    public const LEGACY_FOLDER = 'media';

    protected $signature = 'offsite:mirror
        {--source= : The disk to copy (default: offsite-backup.mirror.source)}
        {--prefix= : Only copy paths under this prefix of the source disk (default: offsite-backup.mirror.prefix)}
        {--relocate-from= : Instead of mirroring, move what an earlier mirror wrote under this prefix of the backup disk (e.g. <name>/media) to the destination}
        {--dry-run : List what would be copied or moved without changing anything}';

    protected $description = 'Copies a disk (e.g. a media bucket) to the backup disk under <name>-media/ (mirror.destination); never deletes';

    /**
     * The prefix on the backup disk that offsite:mirror copies into, without slashes.
     */
    public static function destination(): string
    {
        $name = trim((string) config('offsite-backup.name'), '/');
        $destination = config('offsite-backup.mirror.destination');

        return is_string($destination) && trim($destination, '/') !== '' ? trim($destination, '/') : $name.'-media';
    }

    public function handle(): int
    {
        $relocateFrom = $this->option('relocate-from');

        if (is_string($relocateFrom) && $relocateFrom !== '') {
            return $this->relocate(trim($relocateFrom, '/'));
        }

        $sourceName = $this->option('source') ?: config('offsite-backup.mirror.source');
        $prefix = trim((string) ($this->option('prefix') ?? config('offsite-backup.mirror.prefix', '')), '/');
        $targetName = (string) config('offsite-backup.disk');
        $target = self::destination().'/';

        if (! is_string($sourceName) || $sourceName === '') {
            $this->components->error('No source disk: set OFFSITE_MIRROR_SOURCE (offsite-backup.mirror.source) or pass --source.');

            return self::INVALID;
        }

        if ($sourceName === $targetName) {
            $this->components->error("The source disk '{$sourceName}' is the backup disk; refusing to mirror it into itself.");

            return self::INVALID;
        }

        $this->line("Mirroring {$sourceName}:".($prefix === '' ? '/' : "{$prefix}/")." to {$targetName}:{$target}".($this->option('dry-run') ? ' (dry run)' : ''));
        $this->warnInsideBackupFolder($target);

        $copied = $unchanged = $failed = 0;

        try {
            $source = $this->disk($sourceName);
            $backup = $this->disk($targetName);
            $backedUp = $this->sizes($backup, $target);

            foreach ($this->files($source, $prefix) as $file) {
                $path = $file->path();

                if (array_key_exists($path, $backedUp) && $backedUp[$path] !== null && $backedUp[$path] === $file->fileSize()) {
                    $unchanged++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("  would copy {$path}", verbosity: 'v');
                    $copied++;

                    continue;
                }

                try {
                    $this->copy($source, $backup, $path, $target.$path);
                    $copied++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->components->error("Failed to copy {$path}: {$exception->getMessage()}");
                    report($exception);
                }
            }
        } catch (Throwable $exception) {
            $this->components->error("Mirror aborted: {$exception->getMessage()}");
            report($exception);
            $this->summary($copied, $unchanged, $failed);

            return self::FAILURE;
        }

        $this->summary($copied, $unchanged, $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function relocate(string $from): int
    {
        $diskName = (string) config('offsite-backup.disk');
        $name = trim((string) config('offsite-backup.name'), '/');
        $to = self::destination();
        $dryRun = (bool) $this->option('dry-run');

        if ($from === $name || $from === '' || str_starts_with("{$to}/", "{$from}/") || str_starts_with("{$from}/", "{$to}/")) {
            $this->components->error("Refusing to relocate '{$from}/' to '{$to}/': ".($from === $name || $from === ''
                ? 'that would move the backups themselves.'
                : 'one contains the other.').' Pass the old mirror folder, e.g. --relocate-from='.$name.'/'.self::LEGACY_FOLDER);

            return self::INVALID;
        }

        $this->line("Relocating {$diskName}:{$from}/ to {$diskName}:{$to}/".($dryRun ? ' (dry run)' : ''));
        $this->warnInsideBackupFolder("{$to}/");

        $moved = $already = $failed = 0;

        try {
            $disk = $this->disk($diskName);
            // Listed up front: the loop deletes from the listing it would otherwise be paging through.
            $old = $this->sizes($disk, "{$from}/");
            $new = $this->sizes($disk, "{$to}/");
        } catch (Throwable $exception) {
            $this->components->error("Relocation aborted: {$exception->getMessage()}");
            report($exception);

            return self::FAILURE;
        }

        foreach ($old as $path => $size) {
            $source = "{$from}/{$path}";
            $target = "{$to}/{$path}";
            $present = array_key_exists($path, $new) && $new[$path] !== null && $new[$path] === $size;

            if ($dryRun) {
                $this->line($present ? "  would delete {$source} (already at {$target})" : "  would move {$source} to {$target}", verbosity: 'v');
                if ($present) {
                    $already++;
                } else {
                    $moved++;
                }

                continue;
            }

            try {
                if (! $present) {
                    $this->serverSideCopy($disk, $source, $target);
                    $expected = $size ?? $disk->size($source);
                    $copied = $disk->size($target);

                    if ($copied !== $expected) {
                        throw new RuntimeException("the copy is {$copied} bytes, expected {$expected}; the old key was kept");
                    }
                }

                if (! $disk->delete($source)) {
                    throw new RuntimeException('the backup disk refused to delete the old key');
                }

                if ($present) {
                    $already++;
                } else {
                    $moved++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error("Failed to relocate {$source}: {$exception->getMessage()}");
                report($exception);
            }
        }

        $this->line(($dryRun ? 'Would move' : 'Moved').": {$moved}");
        $this->line('Already at the destination'.($dryRun ? ' (old key would be deleted)' : ' (old key deleted)').": {$already}");
        $this->line("Failed: {$failed}");

        if ($old === []) {
            $this->line("Nothing left under {$from}/.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Copies within one disk without downloading: S3 CopyObject (B2's S3 API supports it, with
     * no egress). Visibility isn't read from the source: B2 and R2 have no per-object ACLs.
     */
    private function serverSideCopy(FilesystemAdapter $disk, string $from, string $to): void
    {
        $disk->getDriver()->copy($from, $to, [Config::OPTION_RETAIN_VISIBILITY => false]);
    }

    private function warnInsideBackupFolder(string $target): void
    {
        $name = trim((string) config('offsite-backup.name'), '/');

        if (str_starts_with($target, "{$name}/")) {
            $this->components->warn("{$target} is inside the backup folder {$name}/: spatie's backup:list, clean and monitor send a HEAD request for every object in it. Set OFFSITE_MIRROR_DESTINATION outside it (default {$name}-media).");
        }
    }

    private function disk(string $name): FilesystemAdapter
    {
        return Storage::disk($name);
    }

    /**
     * @return iterable<FileAttributes>
     */
    private function files(FilesystemAdapter $disk, string $directory): iterable
    {
        foreach ($disk->getDriver()->listContents($directory, true) as $item) {
            if ($item instanceof FileAttributes && ! str_ends_with($item->path(), '/')) {
                yield $item;
            }
        }
    }

    /**
     * Sizes of the objects already backed up, keyed by their path on the source disk.
     *
     * @return array<string, int|null>
     */
    private function sizes(FilesystemAdapter $disk, string $prefix): array
    {
        $sizes = [];

        foreach ($this->files($disk, rtrim($prefix, '/')) as $file) {
            if (str_starts_with($file->path(), $prefix)) {
                $sizes[substr($file->path(), strlen($prefix))] = $file->fileSize();
            }
        }

        return $sizes;
    }

    private function copy(FilesystemAdapter $source, FilesystemAdapter $target, string $from, string $to): void
    {
        $stream = $source->readStream($from);

        if (! is_resource($stream)) {
            throw new RuntimeException('could not open the source object for reading');
        }

        try {
            if (! $target->writeStream($to, $stream)) {
                throw new RuntimeException('the backup disk refused the write');
            }
        } finally {
            fclose($stream);
        }
    }

    private function summary(int $copied, int $unchanged, int $failed): void
    {
        $this->line(($this->option('dry-run') ? 'Would copy' : 'Copied').": {$copied}");
        $this->line("Already backed up: {$unchanged}");
        $this->line("Failed: {$failed}");
    }
}
