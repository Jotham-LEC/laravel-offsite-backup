<?php

namespace Jothamlec\OffsiteBackup\Support;

/**
 * Resolves config/offsite-backup.php into the values the hardened config/backup.php needs.
 *
 * config/backup.php is evaluated before config/offsite-backup.php (alphabetical order), so it
 * can't use config(); it calls Preset::load(__DIR__.'/offsite-backup.php') instead.
 */
final class Preset
{
    /**
     * The published config merged over the package defaults (top-level keys, like mergeConfigFrom).
     *
     * @return array<string, mixed>
     */
    public static function load(?string $publishedPath = null): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require self::defaultConfigPath();

        if ($publishedPath === null || ! is_file($publishedPath)) {
            return $defaults;
        }

        /** @var array<string, mixed> $published */
        $published = require $publishedPath;

        return array_merge($defaults, $published);
    }

    public static function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/offsite-backup.php';
    }

    /**
     * @param  array<string, mixed>  $offsite
     */
    public static function sharedPath(array $offsite, ?string $basePath = null): string
    {
        $configured = $offsite['shared_path'] ?? null;

        return SharedPath::resolve(is_string($configured) ? $configured : null, $basePath ?? base_path());
    }

    /**
     * @param  array<string, mixed>  $offsite
     * @return list<string>
     */
    public static function include(array $offsite, ?string $basePath = null): array
    {
        return self::absolute(self::stringList($offsite['include'] ?? ['.']), self::sharedPath($offsite, $basePath));
    }

    /**
     * @param  array<string, mixed>  $offsite
     * @return list<string>
     */
    public static function exclude(array $offsite, ?string $basePath = null): array
    {
        $basePath ??= base_path();

        return array_values(array_unique([
            ...self::absolute(self::stringList($offsite['exclude'] ?? []), self::sharedPath($offsite, $basePath)),
            $basePath.'/vendor',
            $basePath.'/node_modules',
        ]));
    }

    /**
     * @param  array<string, mixed>  $offsite
     * @return list<string>
     */
    public static function connections(array $offsite): array
    {
        return self::stringList($offsite['connections'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $offsite
     */
    public static function temporaryDirectory(array $offsite): string
    {
        $configured = $offsite['temporary_directory'] ?? null;

        return is_string($configured) && $configured !== '' ? $configured : storage_path('app/backup-temp');
    }

    /**
     * Paths relative to $root become absolute; '.' is $root itself.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function absolute(array $paths, string $root): array
    {
        $root = rtrim($root, '/');

        return array_map(function (string $path) use ($root): string {
            if (str_starts_with($path, '/')) {
                return rtrim($path, '/') ?: '/';
            }

            $path = trim($path, '/');

            return $path === '' || $path === '.' ? $root : $root.'/'.$path;
        }, $paths);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));
    }
}
