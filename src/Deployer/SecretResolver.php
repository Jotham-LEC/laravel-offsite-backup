<?php

namespace Jothamlec\OffsiteBackup\Deployer;

use Closure;
use RuntimeException;

/**
 * Resolves `set('offsite_secrets', [...])` on the machine running `dep`:
 *
 *   'env:VAR'          the local environment
 *   'op://vault/item/field'  1Password CLI (`op read`)
 *   'doppler:NAME'     Doppler CLI (`doppler secrets get NAME --plain`)
 *   'file:/path'       a local file's contents, trimmed
 *   'literal:text'     text as is (for values that look like a source)
 *   a closure          its return value
 *   anything else      the string itself (bucket names, regions, endpoints)
 *
 * A trailing '?' on a source ('op://vault/item/heartbeat?') makes it optional: empty when it fails.
 */
final class SecretResolver
{
    /** @var Closure(string): string */
    private Closure $runLocally;

    /** @var Closure(string): (string|false) */
    private Closure $getenv;

    /**
     * @param  callable(string): string  $runLocally  runs a local shell command and returns stdout; throws on failure
     * @param  (callable(string): (string|false))|null  $getenv
     */
    public function __construct(callable $runLocally, ?callable $getenv = null)
    {
        $this->runLocally = $runLocally(...);
        $this->getenv = $getenv !== null ? $getenv(...) : fn (string $name): string|false => getenv($name);
    }

    /**
     * @param  array<string, mixed>  $sources
     * @return array<string, string>
     */
    public function resolveAll(array $sources): array
    {
        $values = [];

        foreach ($sources as $key => $source) {
            $values[(string) $key] = $this->resolve($source, (string) $key);
        }

        return $values;
    }

    public function resolve(mixed $source, string $key = 'value'): string
    {
        if ($source === null) {
            return '';
        }

        if ($source instanceof Closure) {
            return $this->resolve($source(), $key);
        }

        if (! is_scalar($source)) {
            throw new RuntimeException("{$key}: unsupported secret source of type ".get_debug_type($source).'.');
        }

        $source = (string) $source;

        if (str_starts_with($source, 'literal:')) {
            return substr($source, 8);
        }

        $optional = self::isSource($source) && str_ends_with($source, '?');
        $reference = $optional ? substr($source, 0, -1) : $source;

        try {
            return $this->fetch($reference, $key);
        } catch (RuntimeException $exception) {
            if ($optional) {
                return '';
            }

            throw $exception;
        }
    }

    public static function isSource(string $value): bool
    {
        return (bool) preg_match('#^(env:|op://|doppler:|file:)#', $value);
    }

    /**
     * The shell command a source runs, or null when it doesn't run one.
     */
    public static function command(string $reference): ?string
    {
        if (str_starts_with($reference, 'op://')) {
            return 'op read '.escapeshellarg($reference);
        }

        if (str_starts_with($reference, 'doppler:')) {
            return 'doppler secrets get '.escapeshellarg(substr($reference, 8)).' --plain';
        }

        return null;
    }

    private function fetch(string $reference, string $key): string
    {
        if (str_starts_with($reference, 'env:')) {
            $name = substr($reference, 4);
            $value = ($this->getenv)($name);

            if ($value === false) {
                throw new RuntimeException("{$key}: the local environment variable {$name} isn't set.");
            }

            return $value;
        }

        if (str_starts_with($reference, 'file:')) {
            $path = substr($reference, 5);

            if (str_starts_with($path, '~/') && ($home = ($this->getenv)('HOME')) !== false) {
                $path = $home.substr($path, 1);
            }

            $contents = @file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException("{$key}: can't read {$path}.");
            }

            return trim($contents);
        }

        if (($command = self::command($reference)) !== null) {
            try {
                return trim(($this->runLocally)($command));
            } catch (\Throwable $exception) {
                throw new RuntimeException("{$key}: `{$command}` failed: ".$exception->getMessage(), previous: $exception);
            }
        }

        return $reference;
    }
}
