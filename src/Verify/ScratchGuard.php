<?php

namespace Jothamlec\OffsiteBackup\Verify;

/**
 * Refuses a scratch connection that points at a database being backed up: a restore drill
 * must never write into production.
 */
final class ScratchGuard
{
    private const DEFAULT_PORTS = ['pgsql' => 5432, 'mysql' => 3306, 'mariadb' => 3306];

    /**
     * @param  array<string, mixed>  $scratch  the scratch connection's config
     * @param  list<array<string, mixed>>  $protected  configs (or manifest entries) of backed-up connections
     * @param  list<string>  $protectedNames  names of the backed-up connections
     */
    public static function assertSafe(string $scratchName, array $scratch, array $protected, array $protectedNames = []): void
    {
        if (in_array($scratchName, $protectedNames, true)) {
            throw new VerifyFailed("Refusing to restore into '{$scratchName}': it is one of the backed-up connections.");
        }

        $identity = self::identity($scratch);

        foreach ($protected as $config) {
            if (self::identity($config) === $identity) {
                throw new VerifyFailed(sprintf(
                    "Refusing to restore into '%s': %s is a backed-up database.",
                    $scratchName,
                    $identity,
                ));
            }
        }
    }

    /**
     * host:port/database, with localhost/127.0.0.1/::1 treated as the same host.
     *
     * @param  array<string, mixed>  $config
     */
    public static function identity(array $config): string
    {
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';
        $host = $config['host'] ?? 'localhost';
        $host = is_array($host) ? (string) reset($host) : (string) $host;
        $host = strtolower(trim($host));

        if (in_array($host, ['', 'localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $host = 'localhost';
        }

        $port = $config['port'] ?? null;
        $port = is_numeric($port) ? (int) $port : (self::DEFAULT_PORTS[$driver] ?? 0);
        $database = (string) ($config['database'] ?? '');

        return "{$host}:{$port}/{$database}";
    }
}
