<?php

namespace Jothamlec\OffsiteBackup\Deployer;

use InvalidArgumentException;
use RuntimeException;

/**
 * The marked block `dep offsite:env` maintains in shared/.env. No Laravel or Deployer dependencies,
 * so the recipe can load it on its own.
 *
 * Legacy blocks (pairs of start/end marker prefixes, e.g. a hand-rolled `# >>> off-site backup`
 * block) are removed when the block is written, so their keys don't linger next to ours.
 */
final class EnvBlock
{
    public const START = '# >>> offsite-backup';

    public const END = '# <<< offsite-backup';

    public const LEGACY_MARKERS = [['# >>> off-site backup', '# <<< off-site backup']];

    /**
     * @param  array<string, string|null>  $values
     */
    public static function render(array $values, string $managedBy = 'dep offsite:env'): string
    {
        $block = self::START." (managed by `{$managedBy}`; edits are overwritten)\n";

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("'{$key}' isn't a valid .env key.");
            }

            $value ??= '';

            if (str_contains($value, "'") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new InvalidArgumentException("{$key} contains a single quote or a newline, which .env single quotes can't hold.");
            }

            $block .= "{$key}='{$value}'\n";
        }

        return $block.self::END."\n";
    }

    /**
     * Our marker pair followed by the legacy pairs, validated.
     *
     * @param  array<mixed>  $legacy
     * @return list<array{0: string, 1: string}>
     */
    public static function markerPairs(array $legacy = []): array
    {
        $pairs = [[self::START, self::END]];

        foreach ($legacy as $pair) {
            if (! is_array($pair) || count($pair) !== 2 || ! is_string($start = array_values($pair)[0]) || ! is_string($end = array_values($pair)[1])
                || trim($start) === '' || trim($end) === '' || preg_match('/\R/', $start.$end)) {
                throw new InvalidArgumentException('offsite_legacy_markers must be a list of [start, end] marker strings.');
            }

            if (! in_array([$start, $end], $pairs, true)) {
                $pairs[] = [$start, $end];
            }
        }

        return $pairs;
    }

    /**
     * $env with any existing block (and legacy blocks) removed and $block appended: what the remote
     * rewrite produces.
     *
     * @param  array<mixed>  $legacy
     */
    public static function replaceIn(string $env, string $block, array $legacy = []): string
    {
        foreach (self::markerPairs($legacy) as [$start, $end]) {
            $starts = preg_match_all('/^'.preg_quote($start, '/').'/m', $env);
            $ends = preg_match_all('/^'.preg_quote($end, '/').'/m', $env);

            if ($starts !== $ends) {
                throw new RuntimeException(self::unbalancedMessage($start, $end));
            }

            $pattern = '/^'.preg_quote($start, '/').'.*?^'.preg_quote($end, '/').'[^\n]*\n?/ms';
            $env = (string) preg_replace($pattern, '', $env);
        }

        if ($env !== '' && ! str_ends_with($env, "\n")) {
            $env .= "\n";
        }

        return $env.$block;
    }

    /**
     * The remote shell command: refuse unbalanced markers (sed would delete to the end of the
     * file), drop the old blocks, append the uploaded one, and write the result back with
     * `cat >` so .env keeps its owner, mode and ACLs.
     *
     * @param  array<mixed>  $legacy
     */
    public static function rewriteCommand(string $sharedPath, string $uploadedBlock = '.env.offsite-block', array $legacy = []): string
    {
        $dir = escapeshellarg($sharedPath);
        $block = escapeshellarg($uploadedBlock);
        $checks = '';
        $deletes = '';

        foreach (self::markerPairs($legacy) as [$start, $end]) {
            $s = self::sedRegex($start);
            $e = self::sedRegex($end);
            $checks .= '[ "$(grep -c -e '.escapeshellarg('^'.$s).' .env || true)" = "$(grep -c -e '.escapeshellarg('^'.$e).' .env || true)" ] '
                .'|| { echo '.escapeshellarg(self::unbalancedMessage($start, $end)).' >&2; rm -f '.$block.'; exit 1; }; ';
            $deletes .= '-e '.escapeshellarg("/^{$s}/,/^{$e}/d").' ';
        }

        return "cd {$dir} || exit 1; umask 077; touch .env || exit 1; {$checks}"
            ."{ sed {$deletes}-e '\$a\\' .env; cat {$block}; } > .env.offsite-new && "
            .'cat .env.offsite-new > .env && rm -f .env.offsite-new '.$block;
    }

    /**
     * A literal string as a POSIX basic regular expression (also safe inside sed's /.../).
     */
    public static function sedRegex(string $literal): string
    {
        return (string) preg_replace('/[\\\\.*\[\]^$\/]/', '\\\\$0', $literal);
    }

    private static function unbalancedMessage(string $start, string $end): string
    {
        return "shared/.env has a different number of '{$start}' and '{$end}' lines; fix the block by hand, then run offsite:env again.";
    }
}
