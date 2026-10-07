<?php

namespace Jothamlec\OffsiteBackup\Deployer;

use InvalidArgumentException;

/**
 * The marked block `dep offsite:env` maintains in shared/.env. No Laravel or Deployer dependencies,
 * so the recipe can load it on its own.
 */
final class EnvBlock
{
    public const START = '# >>> offsite-backup';

    public const END = '# <<< offsite-backup';

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
     * $env with any existing block removed and $block appended: what the remote rewrite produces.
     */
    public static function replaceIn(string $env, string $block): string
    {
        $pattern = '/^'.preg_quote(self::START, '/').'.*?^'.preg_quote(self::END, '/').'[^\n]*\n?/ms';
        $env = (string) preg_replace($pattern, '', $env);

        if ($env !== '' && ! str_ends_with($env, "\n")) {
            $env .= "\n";
        }

        return $env.$block;
    }

    /**
     * The remote shell command: drop the old block, append the uploaded one, and write the result
     * back with `cat >` so .env keeps its owner, mode and ACLs.
     */
    public static function rewriteCommand(string $sharedPath, string $uploadedBlock = '.env.offsite-block'): string
    {
        $dir = escapeshellarg($sharedPath);
        $start = str_replace('/', '\/', self::START);
        $end = str_replace('/', '\/', self::END);
        $block = escapeshellarg($uploadedBlock);

        return "cd {$dir} && umask 077 && touch .env && "
            ."{ sed -e '/^{$start}/,/^{$end}/d' -e '\$a\\' .env; cat {$block}; } > .env.offsite-new && "
            .'cat .env.offsite-new > .env && rm -f .env.offsite-new '.$block;
    }
}
