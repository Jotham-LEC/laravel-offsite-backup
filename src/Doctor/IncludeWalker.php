<?php

namespace Jothamlec\OffsiteBackup\Doctor;

use Jothamlec\OffsiteBackup\Support\SystemUser;

/**
 * Walks the include paths the way spatie's Finder will: every directory must be readable
 * (Finder descends into excluded ones too, before spatie filters what it yields, and stops on
 * the first it can't open unless ignore_unreadable_directories is on), and every file that
 * isn't excluded must be readable. It keeps walking past unreadable paths to report them all.
 */
final class IncludeWalker
{
    public const MAX_UNREADABLE = 20;

    /** @var list<string> */
    private array $excludes;

    /**
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     */
    public function __construct(
        private readonly array $include,
        array $exclude,
        private readonly bool $followLinks = false,
    ) {
        $this->excludes = self::resolveExcludes($exclude);
    }

    public static function fromBackupConfig(): self
    {
        /** @var array<string, mixed> $files */
        $files = (array) config('backup.backup.source.files', []);

        return new self(
            array_values(array_filter((array) ($files['include'] ?? []), 'is_string')),
            array_values(array_filter((array) ($files['exclude'] ?? []), 'is_string')),
            (bool) ($files['follow_links'] ?? false),
        );
    }

    public function walk(): WalkResult
    {
        $files = 0;
        $bytes = 0;
        $missing = [];
        $symlink = null;
        $unreadable = [];

        $note = function (string $path, bool $directory) use (&$unreadable): void {
            if (count($unreadable) < self::MAX_UNREADABLE) {
                $unreadable[] = ['path' => $path, 'detail' => self::describe($path), 'directory' => $directory, 'excluded' => $this->excluded($path)];
            }
        };

        foreach ($this->include as $root) {
            if (! file_exists($root)) {
                $missing[] = $root;

                continue;
            }

            if (! is_dir($root)) {
                if (! is_readable($root)) {
                    $note($root, false);
                } else {
                    $files++;
                    $bytes += (int) filesize($root);
                }

                continue;
            }

            $stack = [$root];

            while ($stack !== []) {
                $dir = array_pop($stack);

                // Finder opens every directory, excluded or not, before spatie filters the
                // paths it yields: an unreadable excluded directory stops backup:run too.
                $entries = is_readable($dir) && is_executable($dir) ? @scandir($dir) : false;

                if ($entries === false) {
                    $note($dir, true);

                    continue;
                }

                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    $path = $dir.'/'.$entry;

                    if (is_link($path) && is_dir($path) && ! $this->followLinks) {
                        $symlink ??= $path;

                        continue;
                    }

                    if (is_dir($path)) {
                        $stack[] = $path;

                        continue;
                    }

                    if ($this->excluded($path)) {
                        continue;
                    }

                    if (! is_readable($path)) {
                        $note($path, false);

                        continue;
                    }

                    $files++;
                    $bytes += (int) @filesize($path);
                }
            }
        }

        $first = $unreadable[0] ?? null;

        return new WalkResult($files, $bytes, $first['path'] ?? null, $first['detail'] ?? null, $symlink, $missing, $unreadable);
    }

    public static function describe(string $path): string
    {
        $stat = @stat($path);

        if ($stat === false) {
            return 'cannot stat';
        }

        return sprintf(
            '%s %s:%s mode %04o',
            is_dir($path) ? 'dir' : 'file',
            SystemUser::ownerName($stat['uid']),
            self::groupName($stat['gid']),
            $stat['mode'] & 07777,
        );
    }

    private function excluded(string $path): bool
    {
        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        foreach ($this->excludes as $exclude) {
            if ($real === $exclude || str_starts_with($real, $exclude.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Excludes as spatie resolves them: globs expanded, then realpath'd.
     *
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private static function resolveExcludes(array $exclude): array
    {
        $resolved = [];

        foreach ($exclude as $pattern) {
            $paths = str_contains($pattern, '*') ? (glob($pattern) ?: []) : [$pattern];

            foreach ($paths as $path) {
                if (($real = realpath($path)) !== false) {
                    $resolved[] = $real;
                }
            }
        }

        return $resolved;
    }

    private static function groupName(int $gid): string
    {
        if (function_exists('posix_getgrgid')) {
            $info = posix_getgrgid($gid);

            if (is_array($info)) {
                return $info['name'];
            }
        }

        return (string) $gid;
    }
}
