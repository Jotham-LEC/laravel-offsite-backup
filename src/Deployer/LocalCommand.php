<?php

namespace Jothamlec\OffsiteBackup\Deployer;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs a local command with extra environment variables, streaming its output.
 *
 * Deployer's runLocally(env: ...) prepends `export KEY='value';` to the command, so a failing
 * command prints every secret in Deployer's error message; its named arguments also differ
 * between Deployer versions. Symfony Process passes the variables in the process environment
 * instead, and no command line ever holds a secret.
 */
final class LocalCommand
{
    /**
     * @param  array<string, string>  $env
     * @param  Closure(string): void  $write  receives stdout and stderr as they arrive
     * @return int the exit code
     */
    public static function run(string $command, array $env, Closure $write, ?string $cwd = null, ?float $timeout = 3600): int
    {
        $process = Process::fromShellCommandline($command, $cwd, $env, null, $timeout);
        $process->run(fn (string $type, string $buffer) => $write($buffer));

        return (int) $process->getExitCode();
    }

    /**
     * Non-secret extra .env values (`offsite_env_extra`) as strings: booleans become true/false,
     * null an empty string, closures are called.
     *
     * @param  array<mixed>  $extra
     * @return array<string, string>
     */
    public static function stringValues(array $extra): array
    {
        $values = [];

        foreach ($extra as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException('offsite_env_extra keys must be .env keys like OFFSITE_BACKUP_TIME; got '.var_export($key, true).'.');
            }

            while ($value instanceof Closure) {
                $value = $value();
            }

            $values[$key] = match (true) {
                $value === null => '',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                is_array($value) && array_is_list($value) => implode(',', array_map(fn ($v): string => is_scalar($v) ? (string) $v : throw new InvalidArgumentException("{$key}: only scalar list items are allowed."), $value)),
                default => throw new InvalidArgumentException("{$key}: unsupported value of type ".get_debug_type($value).'.'),
            };
        }

        return $values;
    }

    /**
     * Fails when the local Laravel config is cached: a cached config ignores the environment, so
     * the credentials passed to offsite:verify would never be read.
     */
    public static function assertConfigNotCached(string $projectPath): void
    {
        if (is_file(rtrim($projectPath, '/').'/bootstrap/cache/config.php')) {
            throw new RuntimeException('The local config is cached (bootstrap/cache/config.php), so offsite:verify would ignore the credentials passed to it. Run `php artisan config:clear` first.');
        }
    }
}
