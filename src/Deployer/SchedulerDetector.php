<?php

namespace Jothamlec\OffsiteBackup\Deployer;

/**
 * Finds schedule:run already wired up for an app: /etc/cron.d files, systemd units, and crontab
 * lines other than ours. Installing another crontab line next to one of those runs every
 * scheduled command twice.
 */
final class SchedulerDetector
{
    /**
     * The remote command whose output parse() reads: every cron.d file and systemd unit
     * mentioning schedule:run, each after a "==> path" header.
     */
    public static function gatherCommand(): string
    {
        return 'for f in /etc/cron.d/* /etc/systemd/system/*.service /lib/systemd/system/*.service /usr/lib/systemd/system/*.service; do '
            .'[ -f "$f" ] && grep -qs "schedule:run" "$f" && { echo "==> $f"; cat "$f"; }; '
            .'done; true';
    }

    /**
     * @return array<string, string> path => contents
     */
    public static function parse(string $output): array
    {
        $files = [];
        $current = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^==> (.+)$/', $line, $m)) {
                $current = $m[1];
                $files[$current] = '';

                continue;
            }

            if ($current !== null) {
                $files[$current] .= $line."\n";
            }
        }

        return $files;
    }

    /**
     * Descriptions of the cron.d lines and systemd units running schedule:run in one of $paths.
     *
     * @param  array<string, string>  $files
     * @param  list<string>  $paths  the deploy path (as configured and resolved)
     * @return list<string>
     */
    public static function find(array $files, array $paths): array
    {
        $found = [];

        foreach ($files as $file => $contents) {
            if (str_ends_with($file, '.service')) {
                if (self::mentionsSchedulerFor(self::uncommented($contents), $paths)) {
                    $found[] = "systemd unit {$file}";
                }

                continue;
            }

            foreach (explode("\n", $contents) as $line) {
                if (self::mentionsSchedulerFor(self::uncommented($line), $paths)) {
                    $found[] = "{$file}: ".trim($line);
                }
            }
        }

        return $found;
    }

    /**
     * Crontab lines running schedule:run in one of $paths that don't carry $marker.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function foreignCrontabLines(string $crontab, array $paths, string $marker): array
    {
        $found = [];

        foreach (explode("\n", $crontab) as $line) {
            if (str_contains($line, $marker)) {
                continue;
            }

            if (self::mentionsSchedulerFor(self::uncommented($line), $paths)) {
                $found[] = 'crontab: '.trim($line);
            }
        }

        return $found;
    }

    /**
     * The crontab line offsite:scheduler installs.
     */
    public static function crontabLine(string $currentPath, string $php, string $marker, ?string $umask = null): string
    {
        $prefix = $umask !== null && $umask !== '' ? 'umask '.$umask.' && ' : '';

        return "* * * * * {$prefix}cd ".escapeshellarg($currentPath)." && {$php} artisan schedule:run >> /dev/null 2>&1 {$marker}";
    }

    /**
     * @param  list<string>  $paths
     */
    private static function mentionsSchedulerFor(string $text, array $paths): bool
    {
        if (! str_contains($text, 'schedule:run')) {
            return false;
        }

        foreach ($paths as $path) {
            $path = rtrim($path, '/');

            if ($path !== '' && preg_match('#'.preg_quote($path, '#').'(/|[\s\'"]|$)#m', $text)) {
                return true;
            }
        }

        return false;
    }

    private static function uncommented(string $text): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $text);
    }
}
