<?php

namespace Jothamlec\OffsiteBackup\Verify\Restorers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

/**
 * Restores a SQLite dump into a temporary file with the sqlite3 CLI (the tool spatie dumped it with).
 */
class SqliteRestorer implements Restorer
{
    public function __construct(private readonly string $binary = 'sqlite3') {}

    public function restore(DumpFile $dump, string $workDirectory): RestoredDatabase
    {
        $database = $workDirectory.'/restored-'.Str::random(8).'.sqlite';
        $input = fopen($dump->sqlPath, 'rb');

        if ($input === false) {
            throw new VerifyFailed("Can't read {$dump->name}.");
        }

        $result = Process::timeout(3600)->input($input)->run([$this->binary, '-bail', $database]);

        if (is_resource($input)) {
            fclose($input);
        }

        if (! $result->successful()) {
            throw new VerifyFailed("sqlite3 couldn't restore {$dump->name}: ".trim($result->errorOutput() ?: $result->output()));
        }

        $connection = 'offsite_verify_'.Str::lower(Str::random(8));
        config(["database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $row = (array) DB::connection($connection)->selectOne('PRAGMA integrity_check');
        $integrity = (string) (reset($row) ?: 'unknown');

        return new RestoredDatabase($connection, 'sqlite', integrity: $integrity);
    }
}
