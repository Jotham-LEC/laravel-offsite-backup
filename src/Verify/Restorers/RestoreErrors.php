<?php

namespace Jothamlec\OffsiteBackup\Verify\Restorers;

/**
 * Splits a restore's stderr into errors that fail it and errors that don't.
 */
final class RestoreErrors
{
    /**
     * Never counted: the dump's owners and grants name roles the throwaway database doesn't have.
     */
    public const ALWAYS_IGNORED = [
        '/role "[^"]+" does not exist/',
        '/must be owner of/',
    ];

    /**
     * Settings a newer pg_dump writes that an older server doesn't know (pg_dump 17's
     * transaction_timeout on a 16 server). Ignored only when the server is older than the dump.
     */
    public const NEWER_DUMP_SETTINGS = '/unrecognized configuration parameter "[^"]+"/';

    /**
     * @param  list<string>  $patterns
     * @return array{errors: list<string>, ignored: list<string>}
     */
    public static function classify(string $stderr, array $patterns): array
    {
        $patterns = array_values(array_unique([...self::ALWAYS_IGNORED, ...$patterns]));
        $errors = [];
        $ignored = [];

        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            if (! preg_match('/\bERROR\b/', $line)) {
                continue;
            }

            if (self::matchesAny($line, $patterns)) {
                $ignored[] = trim($line);
            } else {
                $errors[] = trim($line);
            }
        }

        return ['errors' => $errors, 'ignored' => $ignored];
    }

    /**
     * A one-line summary of ignored errors for the report, e.g.
     * '12 ignored restore error(s), not counted: role "shop" does not exist (10), ...'.
     *
     * @param  list<string>  $ignored
     */
    public static function summary(array $ignored): ?string
    {
        if ($ignored === []) {
            return null;
        }

        $kinds = [];

        foreach ($ignored as $line) {
            $kind = trim((string) preg_replace('/^.*?\bERROR:\s*/', '', $line));
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }

        arsort($kinds);
        $shown = array_slice($kinds, 0, 3, true);
        $parts = array_map(fn (string $kind, int $count): string => $count > 1 ? "{$kind} ({$count})" : $kind, array_keys($shown), $shown);

        return sprintf(
            '%d ignored restore error(s), not counted: %s%s',
            count($ignored),
            implode('; ', $parts),
            count($kinds) > 3 ? '; ...' : '',
        );
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAny(string $line, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
