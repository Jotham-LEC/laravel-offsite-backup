<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;

class PathLayout implements Check
{
    public function name(): string
    {
        return 'Paths and symlinks';
    }

    public function run(DoctorContext $context): CheckResult
    {
        /** @var array<string, mixed> $files */
        $files = (array) config('backup.backup.source.files', []);
        $include = array_values(array_filter((array) ($files['include'] ?? []), 'is_string'));
        $exclude = array_values(array_filter((array) ($files['exclude'] ?? []), 'is_string'));
        $relative = is_string($files['relative_path'] ?? null) && $files['relative_path'] !== '' ? $files['relative_path'] : null;
        $followLinks = (bool) ($files['follow_links'] ?? false);
        $base = self::real(base_path());
        $results = [];

        foreach ($include as $path) {
            $real = self::real($path);

            if ($relative === null && ! self::within($real, $base)) {
                $results[] = CheckResult::fail(
                    "{$path} is outside base_path() and relative_path is null: the zip stores absolute paths.",
                    'Set relative_path (the hardened config uses the shared path).',
                );
            } elseif ($relative !== null && ! self::within($real, self::real($relative))) {
                $results[] = CheckResult::warn("{$path} is outside relative_path ({$relative}): it is stored with its absolute path.");
            }

            if (! $followLinks && is_link($path)) {
                $results[] = CheckResult::fail(
                    "Include path {$path} is a symlink and follow_links is false.",
                    'Include its target instead ('.$real.'); under Deployer, include the shared path, not the release.',
                );
            }
        }

        if (! $followLinks && ($symlink = $context->walk()->skippedSymlink) !== null) {
            $results[] = CheckResult::warn(
                "{$symlink} is a symlinked directory and is skipped (follow_links is false).",
                'If its contents matter, include its target. Under Deployer, include the shared path rather than the release.',
            );
        }

        $temporary = config('backup.backup.temporary_directory');

        if (is_string($temporary) && $temporary !== '') {
            $realTemporary = self::real($temporary);
            $excluded = array_filter($exclude, fn (string $path): bool => self::within($realTemporary, self::real($path)));

            foreach ($include as $path) {
                if (self::within($realTemporary, self::real($path)) && $excluded === []) {
                    $results[] = CheckResult::warn(
                        "temporary_directory ({$temporary}) is inside the include path {$path} and not excluded: a leftover temp dir ends up in the next archive.",
                        'Add it to offsite-backup.exclude (storage/app/backup-temp is there by default).',
                    );

                    break;
                }
            }
        }

        return CheckResult::combine($results, 'Include paths, relative_path and temporary_directory are consistent.');
    }

    /**
     * realpath(), or for a path that doesn't exist yet, its nearest existing ancestor's realpath plus the rest.
     */
    public static function real(string $path): string
    {
        $path = rtrim($path, '/') ?: '/';
        $suffix = '';

        while (($real = realpath($path)) === false) {
            $parent = dirname($path);

            if ($parent === $path) {
                return rtrim($path.$suffix, '/');
            }

            $suffix = '/'.basename($path).$suffix;
            $path = $parent;
        }

        return rtrim($real, '/').$suffix;
    }

    public static function within(string $path, string $root): bool
    {
        $root = rtrim($root, '/');

        return $path === $root || str_starts_with($path, $root.'/') || $root === '';
    }
}
