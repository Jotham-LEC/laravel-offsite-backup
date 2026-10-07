<?php

namespace Jothamlec\OffsiteBackup\Support;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

class BinaryLocator
{
    /**
     * The binary's path, looked up in $directory (spatie's dump.dump_binary_path) or on PATH.
     */
    public function find(string $binary, ?string $directory = null): ?string
    {
        if ($directory !== null && $directory !== '') {
            $path = rtrim($directory, '/').'/'.$binary;

            return is_file($path) && is_executable($path) ? $path : null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    /**
     * The first line of `<binary> --version`, or null.
     */
    public function version(string $path): ?string
    {
        $result = Process::timeout(15)->run([$path, '--version']);

        if (! $result->successful()) {
            return null;
        }

        $line = strtok(trim($result->output()), "\n");

        return $line === false ? null : trim($line);
    }

    /**
     * The first major version number in a `--version` line ("pg_dump (PostgreSQL) 16.4" → 16).
     */
    public static function majorVersion(?string $versionLine): ?int
    {
        if ($versionLine !== null && preg_match('/(\d+)(?:\.\d+)*/', $versionLine, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
