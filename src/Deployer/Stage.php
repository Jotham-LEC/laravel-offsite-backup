<?php

namespace Jothamlec\OffsiteBackup\Deployer;

final class Stage
{
    /**
     * Whether a host may run the offsite tasks: its `stage` label (or, without one, its alias)
     * must be in offsite_stages. An empty list allows every host.
     *
     * @param  array<string, mixed>  $labels
     * @param  list<string>  $allowed
     */
    public static function allowed(array $labels, string $alias, array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }

        $stage = is_string($labels['stage'] ?? null) ? $labels['stage'] : $alias;

        return in_array($stage, $allowed, true);
    }

    /**
     * A folder name for the bucket from the application name.
     */
    public static function slug(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9.]+/', '-', $name), '-'));

        return $slug !== '' ? $slug : 'laravel';
    }
}
