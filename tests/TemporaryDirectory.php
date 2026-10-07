<?php

namespace Jothamlec\OffsiteBackup\Tests;

use Illuminate\Filesystem\Filesystem;

/**
 * Temporary directories for tests that are deleted even when a run doesn't finish cleanly:
 * TestCase deletes its sandbox in a finally block; whatever is still registered when PHP exits
 * (a fatal error, a tearDown that never ran) or is interrupted (Ctrl-C, a timeout's SIGTERM) is
 * deleted then; and sandboxes a killed run left behind are swept on the next run.
 */
final class TemporaryDirectory
{
    /** @var array<string, true> */
    private static array $live = [];

    private static bool $registered = false;

    /** Leftovers older than this are from a run that was killed (SIGKILL, power loss). */
    public const STALE_AFTER_SECONDS = 6 * 3600;

    public static function make(string $prefix = 'offsite-backup-tests-'): string
    {
        if (! self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'deleteAll']);
            self::trapSignals();
            self::sweepStale();
        }

        $path = rtrim(sys_get_temp_dir(), '/').'/'.$prefix.bin2hex(random_bytes(5));
        mkdir($path, 0700, true);
        self::$live[$path] = true;

        return $path;
    }

    public static function delete(string $path): bool
    {
        unset(self::$live[$path]);

        if (! is_dir($path)) {
            return true;
        }

        // Undo the permission changes the readability tests make before deleting.
        exec('chmod -R u+rwx '.escapeshellarg($path).' 2>/dev/null');

        return (new Filesystem)->deleteDirectory($path) && ! is_dir($path);
    }

    public static function deleteAll(): void
    {
        foreach (array_keys(self::$live) as $path) {
            self::delete($path);
        }
    }

    /**
     * Deletes this user's offsite-backup-tests-* directories older than STALE_AFTER_SECONDS.
     */
    public static function sweepStale(?int $now = null): int
    {
        $swept = 0;
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        foreach (glob(rtrim(sys_get_temp_dir(), '/').'/offsite-backup-tests-*', GLOB_ONLYDIR) ?: [] as $path) {
            $mtime = @filemtime($path);

            if (isset(self::$live[$path]) || $mtime === false || ($now ?? time()) - $mtime < self::STALE_AFTER_SECONDS
                || ($uid !== null && @fileowner($path) !== $uid)) {
                continue;
            }

            if (self::delete($path)) {
                $swept++;
            }
        }

        return $swept;
    }

    private static function trapSignals(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            $previous = pcntl_signal_get_handler($signal);

            pcntl_signal($signal, function (int $number, mixed $info) use ($previous): void {
                self::deleteAll();

                if (is_callable($previous)) {
                    $previous($number, $info);
                }

                exit(128 + $number);
            });
        }
    }

    /**
     * @return list<string>
     */
    public static function live(): array
    {
        return array_keys(self::$live);
    }
}
