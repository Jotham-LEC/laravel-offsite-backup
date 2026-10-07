<?php

namespace Jothamlec\OffsiteBackup\Verify;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Jothamlec\OffsiteBackup\Manifest\AddOffsiteManifest;
use Jothamlec\OffsiteBackup\Support\Docker;
use Jothamlec\OffsiteBackup\Support\Preset;
use Jothamlec\OffsiteBackup\Verify\HealthChecks\HealthCheck;
use Jothamlec\OffsiteBackup\Verify\HealthChecks\MinimumTables;
use Jothamlec\OffsiteBackup\Verify\Restorers\CleansUp;
use Jothamlec\OffsiteBackup\Verify\Restorers\DockerPostgresRestorer;
use Jothamlec\OffsiteBackup\Verify\Restorers\RestoreErrors;
use Jothamlec\OffsiteBackup\Verify\Restorers\Restorer;
use Jothamlec\OffsiteBackup\Verify\Restorers\ServerRestorer;
use Jothamlec\OffsiteBackup\Verify\Restorers\SqliteRestorer;
use Throwable;

/**
 * Downloads a backup, decrypts it, restores its dumps into throwaway databases and checks them.
 * Meant for a laptop, CI or a dedicated box: never production.
 */
class Verifier
{
    /** @var list<string> */
    private array $temporaryConnections = [];

    public function __construct(
        private readonly BackupLocator $locator,
        private readonly ArchiveExtractor $extractor,
        private readonly Docker $docker = new Docker,
    ) {}

    public function verify(VerifyOptions $options): VerifyReport
    {
        $report = new VerifyReport;
        $work = rtrim(sys_get_temp_dir(), '/').'/offsite-verify-'.Str::random(10);
        File::ensureDirectoryExists($work, 0700);

        try {
            $this->run($options, $report, $work);
        } catch (VerifyFailed $exception) {
            $report->add('Error', Status::Fail, $exception->getMessage());
        } catch (Throwable $exception) {
            $report->add('Error', Status::Fail, $exception::class.': '.$exception->getMessage());
        } finally {
            foreach ($this->temporaryConnections as $connection) {
                DB::purge($connection);
            }

            if ($options->keep) {
                $report->keptAt = $work;
            } else {
                File::deleteDirectory($work);
            }
        }

        return $report;
    }

    private function run(VerifyOptions $options, VerifyReport $report, string $work): void
    {
        $backup = $this->locator->find($options->disk, $options->name, $options->backup);
        $date = CarbonImmutable::instance($backup->date());
        $ageHours = $date->diffInMinutes(CarbonImmutable::now(), true) / 60;

        $report->backup = [
            'disk' => $options->disk,
            'path' => $backup->path(),
            'date' => $date->toIso8601String(),
            'age_hours' => round($ageHours, 1),
        ];

        if ($options->backup === null && $ageHours > $options->maximumAgeHours) {
            $report->add('Backup', Status::Fail, sprintf('Newest is %s, %.1fh old (more than %dh): backups have stopped.', $backup->path(), $ageHours, $options->maximumAgeHours));
        } else {
            $report->add('Backup', Status::Pass, sprintf('%s, %.1fh old.', $backup->path(), $ageHours));
        }

        $zipPath = $work.'/backup.zip';
        $this->download($backup->stream(), $zipPath);
        $size = (int) filesize($zipPath);
        $report->backup['bytes'] = $size;
        $report->add('Download', Status::Pass, self::humanBytes($size).' streamed to a temp dir.');

        $extracted = $work.'/extracted';
        $archive = $this->extractor->extract($zipPath, $extracted, $options->password);
        @unlink($zipPath);
        $report->backup['encryption'] = $archive['encryption'];

        if ($archive['encryption'] === 'aes') {
            $report->add('Decrypt', Status::Pass, sprintf('AES-encrypted; %d entries extracted.', count($archive['entries'])));
        } else {
            $report->add('Decrypt', Status::Warn, sprintf('The archive is %s; %d entries extracted.', $archive['encryption'] === 'none' ? 'NOT encrypted' : 'encrypted with weak ZipCrypto', count($archive['entries'])));
        }

        $this->readManifest($extracted, $report);
        $this->checkFiles($archive['entries'], $extracted, $options, $report);
        $this->checkDatabases($extracted, $work, $options, $report);
    }

    /**
     * @param  resource  $stream
     */
    private function download($stream, string $path): void
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new VerifyFailed("Can't write {$path}.");
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private function readManifest(string $extracted, VerifyReport $report): void
    {
        $path = $extracted.'/'.AddOffsiteManifest::FILENAME;

        if (! is_file($path)) {
            $report->add('Manifest', Status::Warn, AddOffsiteManifest::FILENAME.' is missing (made before this package, or offsite-backup.manifest is off).');

            return;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            $report->add('Manifest', Status::Warn, AddOffsiteManifest::FILENAME.' is not valid JSON.');

            return;
        }

        $report->manifest = $manifest;
        $skipped = is_array($manifest['skipped'] ?? null) ? count($manifest['skipped']) : 0;
        $report->add('Manifest', Status::Pass, sprintf(
            'Made %s by %s %s on %s; %d skipped item(s) recorded.',
            $manifest['created_at'] ?? '?',
            $manifest['package'] ?? '?',
            $manifest['package_version'] ?? '?',
            $manifest['hostname'] ?? '?',
            $skipped,
        ));
    }

    /**
     * @param  list<string>  $entries
     */
    private function checkFiles(array $entries, string $extracted, VerifyOptions $options, VerifyReport $report): void
    {
        $files = array_values(array_filter($entries, fn (string $entry): bool => ! str_ends_with($entry, '/')
            && ! str_starts_with($entry, 'db-dumps/')
            && $entry !== AddOffsiteManifest::FILENAME));

        $missing = [];

        foreach ($options->expectedPaths as $expected) {
            $expected = trim($expected, '/');
            $found = array_filter($entries, fn (string $entry): bool => rtrim($entry, '/') === $expected || str_starts_with($entry, $expected.'/'));

            if ($found === []) {
                $missing[] = $expected;
            }
        }

        $results = [];

        if ($missing !== []) {
            $results[] = CheckResult::fail('Missing from the archive: '.implode(', ', $missing).'.');
        }

        if (count($files) < $options->minimumFiles) {
            $results[] = CheckResult::fail(sprintf('%d files, expected at least %d.', count($files), $options->minimumFiles));
        }

        $report->backup['files'] = count($files);
        $report->addResult('Files', CheckResult::combine($results, sprintf('%d files; %s present.', count($files), $options->expectedPaths === [] ? 'no expected paths' : implode(', ', $options->expectedPaths))));
    }

    private function checkDatabases(string $extracted, string $work, VerifyOptions $options, VerifyReport $report): void
    {
        $dumps = glob($extracted.'/db-dumps/*') ?: [];
        $expectDumps = $options->expectedConnections !== [] || ! empty($report->manifest['databases'] ?? []);

        if ($dumps === []) {
            $report->add('Databases', $expectDumps ? Status::Fail : Status::Pass, $expectDumps ? 'No database dumps in the archive, but connections are configured.' : 'No database dumps (none configured).');

            return;
        }

        foreach ($dumps as $path) {
            $this->checkDump($path, $work, $options, $report);
        }
    }

    private function checkDump(string $path, string $work, VerifyOptions $options, VerifyReport $report): void
    {
        $dump = DumpFile::fromArchive($path, $work);
        $step = "Database {$dump->name}";
        $entry = ['dump' => $dump->name, 'driver' => $dump->driver];
        $restorer = null;

        if ($dump->driver === 'pgsql') {
            $entry['postgres'] = $dump->postgresVersions();
        }

        try {
            if (! $dump->looksComplete()) {
                throw new VerifyFailed("{$dump->name} is truncated (no end-of-dump marker).");
            }

            $restorer = $this->restorer($dump, $options, $report);
            $restored = $restorer->restore($dump, $work);

            if ($restored->driver === 'sqlite') {
                $this->temporaryConnections[] = $restored->connection;
            }

            $counts = $restored->rowCounts();
            $entry += [
                'integrity' => $restored->integrity,
                'tables' => $counts,
                'errors' => $restored->errors,
                'ignored_errors' => count($restored->ignoredErrors),
                'notes' => $restored->notes,
            ];

            $results = [];

            if ($restored->integrity !== null && $restored->integrity !== 'ok') {
                $results[] = CheckResult::fail("integrity_check: {$restored->integrity}");
            }

            if ($restored->errors !== []) {
                $results[] = CheckResult::fail(count($restored->errors).' restore error(s), first: '.$restored->errors[0]);
            }

            foreach ($this->healthChecks($dump, $options) as $check) {
                $result = $check->check($restored);
                $entry['health_checks'][] = ['check' => $check->name(), 'status' => $result->status->value, 'detail' => $result->message];
                $results[] = $result;
            }

            $info = array_filter([...$restored->notes, RestoreErrors::summary($restored->ignoredErrors)]);
            $summary = sprintf(
                '%s restore: %d tables, %d rows%s.%s',
                $dump->driver,
                count($counts),
                array_sum($counts),
                $restored->integrity !== null ? ", integrity {$restored->integrity}" : '',
                $info === [] ? '' : ' Info: '.implode('; ', $info).'.',
            );
            $result = CheckResult::combine($results);
            $report->add($step, $result->status, $summary.($result->status === Status::Pass ? '' : ' '.$this->failures($results)));
        } catch (Throwable $exception) {
            $message = $exception instanceof VerifyFailed ? $exception->getMessage() : $exception::class.': '.$exception->getMessage();
            $report->add($step, Status::Fail, $message);
            $entry['error'] = $message;
        } finally {
            if ($restorer instanceof CleansUp) {
                $restorer->cleanup();
            }

            @unlink($dump->sqlPath);
        }

        $report->databases[] = $entry;
    }

    private function restorer(DumpFile $dump, VerifyOptions $options, VerifyReport $report): Restorer
    {
        if ($dump->driver === 'sqlite') {
            return new SqliteRestorer;
        }

        if (! in_array($dump->driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new VerifyFailed("Restoring {$dump->driver} dumps isn't supported; checked that it decompresses only.");
        }

        $scratch = $options->scratchConnection;

        if ($scratch === null && $dump->driver === 'pgsql' && $options->docker && $this->docker->available()) {
            return new DockerPostgresRestorer($options->dockerImage, $options->ignoreRestoreErrors);
        }

        if ($scratch === null || ! is_array(config("database.connections.{$scratch}"))) {
            throw new VerifyFailed("{$dump->name} needs a scratch connection: set OFFSITE_VERIFY_SCRATCH_CONNECTION to a throwaway {$dump->driver} database in config/database.php"
                .($dump->driver === 'pgsql' ? ($options->docker ? ', or make Docker available for a throwaway postgres container.' : ' (or turn verify.docker on).') : '.'));
        }

        ScratchGuard::assertSafe(
            $scratch,
            (array) config("database.connections.{$scratch}"),
            $this->protectedDatabases($options, $report),
            $options->expectedConnections,
        );

        return new ServerRestorer($scratch, $options->ignoreRestoreErrors, docker: $options->docker ? $this->docker : null, dockerImage: $options->dockerImage);
    }

    /**
     * The backed-up databases: this app's configured connections and those recorded in the manifest.
     *
     * @return list<array<string, mixed>>
     */
    private function protectedDatabases(VerifyOptions $options, VerifyReport $report): array
    {
        $protected = [];

        foreach ($options->expectedConnections as $connection) {
            if (is_array($config = config("database.connections.{$connection}"))) {
                $protected[] = $config;
            }
        }

        foreach ((array) ($report->manifest['databases'] ?? []) as $database) {
            if (is_array($database) && ($database['driver'] ?? null) !== 'sqlite') {
                $protected[] = $database;
            }
        }

        return $protected;
    }

    /**
     * @return list<HealthCheck>
     */
    private function healthChecks(DumpFile $dump, VerifyOptions $options): array
    {
        $checks = [new MinimumTables(['min' => $options->minimumTables])];

        foreach ($options->healthChecks as $definition) {
            [$class, $arguments] = is_array($definition) ? [$definition[0] ?? null, $definition[1] ?? []] : [$definition, []];

            if (! is_string($class) || ! is_a($class, HealthCheck::class, true)) {
                throw new VerifyFailed('Not a '.HealthCheck::class.': '.json_encode($class));
            }

            $arguments = is_array($arguments) ? $arguments : [];

            if (isset($arguments['dump']) && is_string($arguments['dump']) && ! str_contains($dump->name, $arguments['dump'])) {
                continue;
            }

            $checks[] = new $class($arguments);
        }

        return $checks;
    }

    /**
     * @param  list<CheckResult>  $results
     */
    private function failures(array $results): string
    {
        return implode('; ', array_map(
            fn (CheckResult $r): string => $r->message,
            array_filter($results, fn (CheckResult $r): bool => $r->status !== Status::Pass),
        ));
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)).' '.$units[$i];
    }

    /**
     * Expected paths from the config: verify.expected_paths plus the relative include roots.
     *
     * @param  array<string, mixed>  $offsite
     * @return list<string>
     */
    public static function expectedPaths(array $offsite): array
    {
        $verify = (array) ($offsite['verify'] ?? []);
        $paths = array_filter((array) ($verify['expected_paths'] ?? []), 'is_string');

        foreach ((array) ($offsite['include'] ?? []) as $include) {
            if (is_string($include) && $include !== '' && ! str_starts_with($include, '/') && trim($include, '/') !== '.') {
                $paths[] = trim($include, '/');
            }
        }

        return array_values(array_unique(array_map(fn (string $p): string => trim($p, '/'), $paths)));
    }

    /**
     * @param  array<string, mixed>  $offsite
     */
    public static function optionsFromConfig(array $offsite, ?string $password, ?string $backup = null, bool $keep = false): VerifyOptions
    {
        $verify = (array) ($offsite['verify'] ?? []);
        $scratch = $verify['scratch_connection'] ?? null;

        return new VerifyOptions(
            disk: (string) ($offsite['disk'] ?? 'b2'),
            name: (string) ($offsite['name'] ?? ''),
            password: $password,
            backup: $backup,
            keep: $keep,
            scratchConnection: is_string($scratch) && $scratch !== '' ? $scratch : null,
            expectedConnections: Preset::connections($offsite),
            minimumTables: (int) ($verify['minimum_tables'] ?? 1),
            maximumAgeHours: (int) ($verify['maximum_age_hours'] ?? 26),
            minimumFiles: (int) ($verify['minimum_files'] ?? 1),
            expectedPaths: self::expectedPaths($offsite),
            healthChecks: array_values((array) ($verify['health_checks'] ?? [])),
            ignoreRestoreErrors: array_values(array_filter((array) ($verify['ignore_restore_errors'] ?? []), 'is_string')),
            docker: (bool) ($verify['docker'] ?? true),
            dockerImage: is_string($verify['docker_image'] ?? null) && $verify['docker_image'] !== '' ? $verify['docker_image'] : 'postgres:{major}',
        );
    }
}
