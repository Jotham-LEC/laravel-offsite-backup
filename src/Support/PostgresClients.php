<?php

namespace Jothamlec\OffsiteBackup\Support;

/**
 * Finds a psql at least as new as a dump needs: on PATH, in dump_binary_path, or in the places
 * distributions and Homebrew put versioned clients (/usr/lib/postgresql/17/bin, postgresql@17).
 */
class PostgresClients
{
    /** @var list<string> */
    public const GLOBS = [
        '/usr/lib/postgresql/*/bin/psql',
        '/usr/pgsql-*/bin/psql',
        '/opt/homebrew/opt/postgresql@*/bin/psql',
        '/usr/local/opt/postgresql@*/bin/psql',
        '/opt/homebrew/opt/libpq/bin/psql',
        '/usr/local/opt/libpq/bin/psql',
        '/Applications/Postgres.app/Contents/Versions/*/bin/psql',
    ];

    /**
     * @param  list<string>  $globs
     */
    public function __construct(
        private readonly BinaryLocator $binaries = new BinaryLocator,
        private readonly array $globs = self::GLOBS,
    ) {}

    /**
     * The oldest psql whose major version is at least $minimumMajor (any psql when null), with
     * its major version; null when there is none.
     *
     * @return array{path: string, major: int}|null
     */
    public function find(?int $minimumMajor, ?string $directory = null): ?array
    {
        $best = null;

        foreach ($this->candidates($directory) as $path) {
            $major = BinaryLocator::majorVersion($this->binaries->version($path));

            if ($major === null || ($minimumMajor !== null && $major < $minimumMajor)) {
                continue;
            }

            if ($best === null || $major < $best['major']) {
                $best = ['path' => $path, 'major' => $major];
            }
        }

        return $best;
    }

    /**
     * The newest psql found, for error messages.
     */
    public function newestMajor(?string $directory = null): ?int
    {
        $majors = array_filter(array_map(
            fn (string $path): ?int => BinaryLocator::majorVersion($this->binaries->version($path)),
            $this->candidates($directory),
        ));

        return $majors === [] ? null : max($majors);
    }

    /**
     * @return list<string>
     */
    protected function candidates(?string $directory): array
    {
        $paths = [];

        if ($directory !== null && $directory !== '' && ($found = $this->binaries->find('psql', $directory)) !== null) {
            $paths[] = $found;
        }

        if (($found = $this->binaries->find('psql')) !== null) {
            $paths[] = $found;
        }

        foreach ($this->globs as $glob) {
            foreach (glob($glob) ?: [] as $path) {
                if (is_file($path) && is_executable($path)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
