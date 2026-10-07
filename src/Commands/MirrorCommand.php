<?php

namespace Jothamlec\OffsiteBackup\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use RuntimeException;
use Throwable;

/**
 * Copies a Flysystem disk (a media bucket on R2 or S3, say) to the backup disk under
 * `<name>/media/<path>`. Only objects missing from the backup or differing in size are copied,
 * one stream at a time. It never deletes: an object removed from the source stays in the
 * backup, and an Object Lock bucket would refuse the delete anyway.
 */
class MirrorCommand extends Command
{
    public const FOLDER = 'media';

    protected $signature = 'offsite:mirror
        {--source= : The disk to copy (default: offsite-backup.mirror.source)}
        {--prefix= : Only copy paths under this prefix of the source disk (default: offsite-backup.mirror.prefix)}
        {--dry-run : List what would be copied without copying}';

    protected $description = 'Copies a disk (e.g. a media bucket) to the backup disk under <name>/media/; never deletes';

    public function handle(): int
    {
        $sourceName = $this->option('source') ?: config('offsite-backup.mirror.source');
        $prefix = trim((string) ($this->option('prefix') ?? config('offsite-backup.mirror.prefix', '')), '/');
        $targetName = (string) config('offsite-backup.disk');
        $target = trim((string) config('offsite-backup.name'), '/').'/'.self::FOLDER.'/';

        if (! is_string($sourceName) || $sourceName === '') {
            $this->components->error('No source disk: set OFFSITE_MIRROR_SOURCE (offsite-backup.mirror.source) or pass --source.');

            return self::INVALID;
        }

        if ($sourceName === $targetName) {
            $this->components->error("The source disk '{$sourceName}' is the backup disk; refusing to mirror it into itself.");

            return self::INVALID;
        }

        $this->line("Mirroring {$sourceName}:".($prefix === '' ? '/' : "{$prefix}/")." to {$targetName}:{$target}".($this->option('dry-run') ? ' (dry run)' : ''));

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
