<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Illuminate\Support\Env;
use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\Preset;

/**
 * backup.backup.source.databases must name the connections to dump, not follow DB_CONNECTION:
 * that is SQLite on a laptop, and when .env lacks it Laravel's default ('mysql', or 'sqlite' in
 * newer skeletons) is dumped instead of the real database.
 */
class DatabasesAreExplicit implements Check
{
    public function name(): string
    {
        return 'Databases explicit';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $databases = array_values(array_filter((array) config('backup.backup.source.databases', []), 'is_string'));
        $listed = $databases === [] ? 'none' : implode(', ', $databases);
        $results = [];

        if ($this->derivedFromDbConnection()) {
            $unset = ! app()->configurationIsCached() && Env::get('DB_CONNECTION') === null;

            $results[] = CheckResult::fail(
                "config/backup.php takes backup.source.databases from DB_CONNECTION (now: {$listed})"
                .($unset ? ", and DB_CONNECTION isn't set, so that is config('database.default')'s fallback." : '.'),
                'List the connections explicitly: the hardened config (`php artisan offsite:install --write --force`) reads OFFSITE_BACKUP_CONNECTIONS, e.g. pgsql.',
            );
        }

        foreach ($databases as $connection) {
            if (! is_array(config("database.connections.{$connection}"))) {
                $results[] = CheckResult::fail("backup.source.databases names '{$connection}', which isn't in config/database.php.", 'Set OFFSITE_BACKUP_CONNECTIONS to the connections to dump.');
            }
        }

        $offsite = Preset::connections((array) config('offsite-backup', []));

        if ($offsite !== [] && $offsite !== $databases) {
            $results[] = CheckResult::warn(
                'backup.source.databases ('.$listed.') differs from offsite-backup.connections ('.implode(', ', $offsite).').',
                'config/backup.php isn\'t the hardened one; regenerate it with `php artisan offsite:install --write --force`.',
            );
        }

        if ($databases === [] && $results === []) {
            return CheckResult::warn('No databases are backed up.', 'Set OFFSITE_BACKUP_CONNECTIONS (e.g. pgsql), unless this app has no database.');
        }

        return CheckResult::combine($results, "Dumping {$listed}, listed explicitly.");
    }

    protected function backupConfigPath(): string
    {
        return config_path('backup.php');
    }

    private function derivedFromDbConnection(): bool
    {
        $source = @file_get_contents($this->backupConfigPath());

        if ($source === false || ! preg_match_all("/'databases'\\s*=>/", $source, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as [, $offset]) {
            // The value: up to the next array key or the end of the enclosing array.
            $value = substr($source, $offset, 400);
            $value = (preg_split("/\\n\\s*(?:'[a-z_]+'\\s*=>|\\],)/", $value, 2) ?: [$value])[0];

            if (str_contains($value, 'DB_CONNECTION') || str_contains($value, 'database.default')) {
                return true;
            }
        }

        return false;
    }
}
