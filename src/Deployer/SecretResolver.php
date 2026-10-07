<?php

namespace Jothamlec\OffsiteBackup\Deployer;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Resolves `set('offsite_secrets', [...])` on the machine running `dep`:
 *
 *   'env:VAR'          the local environment
 *   'op://vault/item/field'  1Password CLI; resolveAll() fetches every op:// source with ONE `op inject`
 *   'doppler:NAME'     Doppler CLI (`doppler secrets get NAME --plain`)
 *   'file:/path'       a local file's contents, trimmed
 *   'literal:text'     text as is (for values that look like a source)
 *   a closure          its return value
 *   anything else      the string itself (bucket names, regions, endpoints)
 *
 * A trailing '?' on a source ('op://vault/item/heartbeat?') makes it optional: empty when it fails.
 *
 * Commands run through Symfony Process directly, not Deployer's runLocally(), so secret values
 * never pass through Deployer's logger or its error messages.
 */
final class SecretResolver
{
    /** @var Closure(list<string>, ?string): string */
    private Closure $run;

    /** @var Closure(string): (string|false) */
    private Closure $getenv;

    /**
     * @param  (callable(list<string>, ?string): string)|null  $run  runs a local command (argv, stdin) and returns stdout; throws on failure
     * @param  (callable(string): (string|false))|null  $getenv
     */
    public function __construct(?callable $run = null, ?callable $getenv = null)
    {
        $this->run = $run !== null ? $run(...) : self::processRunner();
        $this->getenv = $getenv !== null ? $getenv(...) : fn (string $name): string|false => getenv($name);
    }

    /**
     * Every source resolved. All op:// sources are read with a single `op inject`, so 1Password
     * asks for approval once per run rather than once per secret.
     *
     * With $secretsFile, the KEY=VALUE pairs in that file are used instead: keys it defines are
     * never fetched, and keys only it defines are added.
     *
     * @param  array<string, mixed>  $sources
     * @return array<string, string>
     */
    public function resolveAll(array $sources, ?string $secretsFile = null): array
    {
        $fromFile = $secretsFile !== null ? $this->readSecretsFile($secretsFile) : [];
        $normalized = [];
        $op = [];

        foreach ($sources as $key => $source) {
            $key = (string) $key;

            if (array_key_exists($key, $fromFile)) {
                continue;
            }

            $normalized[$key] = $source = $this->evaluate($source, $key);

            if (str_starts_with($source, 'op://')) {
                $op[$key] = $source;
            }
        }

        $injected = $this->injectAll($op);
        $values = [];

        foreach ($sources as $key => $source) {
            $key = (string) $key;

            $values[$key] = match (true) {
                array_key_exists($key, $fromFile) => $fromFile[$key],
                array_key_exists($key, $injected) => $injected[$key],
                default => $this->resolve($normalized[$key], $key),
            };
        }

        return [...$values, ...array_diff_key($fromFile, $values)];
    }

    public function resolve(mixed $source, string $key = 'value'): string
    {
        $source = $this->evaluate($source, $key);

        if (str_starts_with($source, 'literal:')) {
            return substr($source, 8);
        }

        [$reference, $optional] = self::split($source);

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
     * The shell command a single source runs, or null when it doesn't run one.
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

    /**
     * The `op inject` template for $references (key => op:// reference), each value wrapped in
     * markers that can't occur in a secret, so any value (quotes, $, newlines) parses back exactly.
     *
     * @param  array<string, string>  $references
     */
    public static function injectTemplate(array $references, string $nonce): string
    {
        $template = '';
        $i = 0;

        foreach ($references as $key => $reference) {
            if (str_contains($reference, '{{') || str_contains($reference, '}}') || preg_match('/\s$|^\s|\R/', $reference)) {
                throw new RuntimeException("{$key}: the 1Password reference can't be used in an op inject template.");
            }

            $template .= "<<{$nonce}:{$i}>>{{ {$reference} }}<</{$nonce}:{$i}>>\n";
            $i++;
        }

        return $template;
    }

    /**
     * KEY=VALUE pairs from a local file (blank lines, # comments and `export ` allowed; values may
     * be wrapped in single or double quotes). Refuses files other users can read.
     *
     * @return array<string, string>
     */
    public function readSecretsFile(string $path): array
    {
        if (str_starts_with($path, '~/') && ($home = ($this->getenv)('HOME')) !== false) {
            $path = $home.substr($path, 1);
        }

        clearstatcache(true, $path);

        if (! is_file($path) || ($perms = @fileperms($path)) === false) {
            throw new RuntimeException("offsite_secrets_file: can't read {$path}.");
        }

        if (($perms & 0077) !== 0) {
            throw new RuntimeException(sprintf('offsite_secrets_file: %s is readable by other users (mode %04o); run `chmod 600 %s`.', $path, $perms & 07777, $path));
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("offsite_secrets_file: can't read {$path}.");
        }

        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $number => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                throw new RuntimeException(sprintf('offsite_secrets_file: line %d of %s isn\'t KEY=VALUE.', $number + 1, $path));
            }

            $value = $m[2];

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }

            $values[$m[1]] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $sources  key => op:// source, optionally ending in '?'
     * @return array<string, string>
     */
    private function injectAll(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $required = [];
        $optional = [];

        foreach ($sources as $key => $source) {
            [$reference, $isOptional] = self::split($source);

            if ($isOptional) {
                $optional[$key] = $reference;
            } else {
                $required[$key] = $reference;
            }
        }

        try {
            return $this->inject([...$required, ...$optional]);
        } catch (RuntimeException $exception) {
            if ($optional === []) {
                throw $exception;
            }
        }

        // An optional reference may be what failed: one more try without them.
        $values = $required === [] ? [] : $this->inject($required);

        return [...$values, ...array_map(fn (): string => '', $optional)];
    }

    /**
     * @param  array<string, string>  $references
     * @return array<string, string>
     */
    private function inject(array $references): array
    {
        $nonce = bin2hex(random_bytes(12));
        $template = self::injectTemplate($references, $nonce);

        try {
            $output = ($this->run)(['op', 'inject'], $template);
        } catch (\Throwable $exception) {
            throw new RuntimeException('`op inject` failed for '.implode(', ', array_keys($references)).': '.$exception->getMessage(), previous: $exception);
        }

        $values = [];
        $i = 0;

        foreach (array_keys($references) as $key) {
            $start = "<<{$nonce}:{$i}>>";
            $end = "<</{$nonce}:{$i}>>";
            $from = strpos($output, $start);
            $to = $from === false ? false : strpos($output, $end, $from + strlen($start));

            if ($from === false || $to === false) {
                throw new RuntimeException("{$key}: couldn't find the value in op inject's output.");
            }

            $values[$key] = substr($output, $from + strlen($start), $to - $from - strlen($start));
            $i++;
        }

        return $values;
    }

    private function evaluate(mixed $source, string $key): string
    {
        while ($source instanceof Closure) {
            $source = $source();
        }

        if ($source === null) {
            return '';
        }

        if (! is_scalar($source)) {
            throw new RuntimeException("{$key}: unsupported secret source of type ".get_debug_type($source).'.');
        }

        return is_bool($source) ? ($source ? 'true' : 'false') : (string) $source;
    }

    /**
     * @return array{0: string, 1: bool} the reference without a trailing '?', and whether it had one
     */
    private static function split(string $source): array
    {
        $optional = self::isSource($source) && str_ends_with($source, '?');

        return [$optional ? substr($source, 0, -1) : $source, $optional];
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

        $argv = match (true) {
            str_starts_with($reference, 'op://') => ['op', 'read', $reference],
            str_starts_with($reference, 'doppler:') => ['doppler', 'secrets', 'get', substr($reference, 8), '--plain'],
            default => null,
        };

        if ($argv === null) {
            return $reference;
        }

        try {
            return trim(($this->run)($argv, null));
        } catch (\Throwable $exception) {
            throw new RuntimeException("{$key}: `".self::command($reference).'` failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return Closure(list<string>, ?string): string
     */
    private static function processRunner(): Closure
    {
        return function (array $command, ?string $input): string {
            $process = new Process($command, null, null, $input, 300);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'exit code '.$process->getExitCode());
            }

            return $process->getOutput();
        };
    }
}
