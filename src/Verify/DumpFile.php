<?php

namespace Jothamlec\OffsiteBackup\Verify;

/**
 * A database dump from the archive's db-dumps/, decompressed to plain SQL.
 */
final class DumpFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $sqlPath,
        public readonly string $driver,
    ) {}

    /**
     * spatie names dumps "{dumper}-{database}.sql[.gz]": sqlite, postgresql, mysql, mariadb, mongodb.
     */
    public static function fromArchive(string $path, string $workDirectory): self
    {
        $name = basename($path);
        $sqlPath = $workDirectory.'/'.preg_replace('/\.gz$/', '', $name);

        if (str_ends_with($name, '.gz')) {
            self::gunzip($path, $sqlPath);
        } else {
            copy($path, $sqlPath);
        }

        return new self($name, $sqlPath, self::detectDriver($name, $sqlPath));
    }

    public static function detectDriver(string $name, string $sqlPath): string
    {
        $prefix = strtolower((string) strtok($name, '-'));

        $driver = match ($prefix) {
            'sqlite' => 'sqlite',
            'postgresql', 'pgsql' => 'pgsql',
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'mongodb' => 'mongodb',
            default => null,
        };

        if ($driver !== null) {
            return $driver;
        }

        $head = (string) @file_get_contents($sqlPath, length: 4096);

        return match (true) {
            str_contains($head, 'PostgreSQL database dump') => 'pgsql',
            str_contains($head, 'MariaDB dump') => 'mariadb',
            str_contains($head, 'MySQL dump') => 'mysql',
            str_contains($head, 'PRAGMA foreign_keys') || str_contains($head, 'BEGIN TRANSACTION') => 'sqlite',
            default => 'unknown',
        };
    }

    /**
     * The major versions in a pg_dump header: "Dumped from database version 16.4" (the server)
     * and "Dumped by pg_dump version 17.2" (the client that made the dump). A dump restores
     * reliably only with a psql and a server at least as new as the pg_dump that made it:
     * pg_dump 17 writes settings such as transaction_timeout that a 16 server rejects.
     *
     * @return array{server: ?int, dump: ?int}
     */
    public function postgresVersions(): array
    {
        $head = (string) @file_get_contents($this->sqlPath, length: 16384);

        $major = fn (string $label): ?int => preg_match('/^-- Dumped '.$label.' version (\d+)/m', $head, $m) ? (int) $m[1] : null;

        return ['server' => $major('from database'), 'dump' => $major('by pg_dump')];
    }

    /**
     * The PostgreSQL major version a restore needs: the pg_dump's, else the server's.
     */
    public function postgresMajorNeeded(): ?int
    {
        $versions = $this->postgresVersions();

        return $versions['dump'] ?? $versions['server'];
    }

    /**
     * Whether the dump ends the way its tool ends a complete dump (a cut-off upload or a killed
     * dump process leaves a truncated file that still restores partly).
     */
    public function looksComplete(): bool
    {
        $size = (int) @filesize($this->sqlPath);
        $handle = @fopen($this->sqlPath, 'rb');

        if ($handle === false || $size === 0) {
            return false;
        }

        fseek($handle, max(0, $size - 4096));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        return match ($this->driver) {
            'pgsql' => str_contains($tail, 'PostgreSQL database dump complete'),
            'mysql', 'mariadb' => str_contains($tail, 'Dump completed'),
            'sqlite' => (bool) preg_match('/(COMMIT|END TRANSACTION);\s*$/', $tail),
            default => true,
        };
    }

    private static function gunzip(string $from, string $to): void
    {
        $in = @gzopen($from, 'rb');
        $out = @fopen($to, 'wb');

        if ($in === false || $out === false) {
            throw new VerifyFailed("Can't decompress {$from}.");
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 1048576);

            if ($chunk === false) {
                throw new VerifyFailed("Can't decompress {$from}: the gzip stream is corrupt.");
            }

            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);
    }
}
