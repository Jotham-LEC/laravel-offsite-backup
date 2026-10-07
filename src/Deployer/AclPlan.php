<?php

namespace Jothamlec\OffsiteBackup\Deployer;

/**
 * Works out which ACL entries `offsite:acl` must add so a user can read a tree, from
 * `getfacl -p` output, without ever narrowing what that user (or anyone) can do now.
 *
 * `setfacl -m u:<user>:rX` replaces the user's entry: an existing `u:www-data:rwx` would become
 * r-x. Worse, a new named entry takes precedence over the group entries, so a user who could
 * write through its group (deploy:www-data 664 files) would lose that too. So for each path the
 * new entry is the union of the user's current entry, the access the user has now (by any
 * route), and r (plus x on directories); paths the user can already read are left alone.
 * Default ACLs on directories get the same treatment.
 */
final class AclPlan
{
    private const R = 4;

    private const W = 2;

    private const X = 1;

    /** @var array<string, array<string, true>> spec => path => true */
    private array $changes = [];

    /** @var array<string, true> */
    private array $readable = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  list<string>  $groups  the user's groups (`id -nG <user>`)
     */
    public function __construct(private readonly string $user, private readonly array $groups) {}

    /**
     * The groups in `id -nG` output.
     *
     * @return list<string>
     */
    public static function parseGroups(string $output): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($output)) ?: [], fn (string $g): bool => $g !== ''));
    }

    /**
     * Adds the paths in `getfacl -p` output; $directories says whether they are all directories.
     */
    public function add(string $getfaclOutput, bool $directories): void
    {
        foreach (self::parse($getfaclOutput) as $path => $acl) {
            $this->plan($path, $acl, $directories);
        }
    }

    /**
     * The setfacl -m specs to apply, each with its paths.
     *
     * @return array<string, list<string>>
     */
    public function changes(): array
    {
        ksort($this->changes);

        return array_map(fn (array $paths): array => array_map('strval', array_keys($paths)), $this->changes);
    }

    public function changeCount(): int
    {
        return array_sum(array_map('count', $this->changes));
    }

    public function readableCount(): int
    {
        return count($this->readable);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, array{owner: string, group: string, entries: array<string, int>}>
     */
    public static function parse(string $output): array
    {
        $owners = $groups = $entries = [];
        $path = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '# file: ')) {
                $path = self::unescape(substr($line, 8));
                $owners[$path] = $groups[$path] = '';
                $entries[$path] = [];
            } elseif ($path === null || $line === '') {
                continue;
            } elseif (str_starts_with($line, '# owner: ')) {
                $owners[$path] = self::unescape(substr($line, 9));
            } elseif (str_starts_with($line, '# group: ')) {
                $groups[$path] = self::unescape(substr($line, 9));
            } elseif (! str_starts_with($line, '#')
                && preg_match('/^((?:default:)?(?:user|group|mask|other)):([^:]*):([rwx-]{3})/', $line, $m)) {
                $entries[$path][$m[1].':'.self::unescape($m[2])] = self::bits($m[3]);
            }
        }

        $acls = [];

        foreach ($entries as $file => $fileEntries) {
            $acls[$file] = ['owner' => $owners[$file], 'group' => $groups[$file], 'entries' => $fileEntries];
        }

        return $acls;
    }

    /**
     * @param  array{owner: string, group: string, entries: array<string, int>}  $acl
     */
    private function plan(string $path, array $acl, bool $directory): void
    {
        $required = $directory ? self::R | self::X : self::R;
        $entries = $acl['entries'];
        $named = $entries["user:{$this->user}"] ?? 0;

        if ($acl['owner'] === $this->user) {
            if ((($entries['user:'] ?? 0) & $required) === $required) {
                $this->readable[$path] = true;
            } else {
                $this->warnings[] = "{$this->user} owns {$path} but its owner bits lack ".self::text($required).'; an ACL entry cannot fix that (chmod u+'.($directory ? 'rx' : 'r').').';
            }
        } elseif (($this->effective($acl, '') & $required) === $required) {
            $this->readable[$path] = true;
        } else {
            $this->change('u', $named | $this->effective($acl, '') | $required, $path);
        }

        if ($directory) {
            $this->planDefault($path, $acl);
        }
    }

    /**
     * The default ACL decides what new files and directories under $path give the user.
     *
     * @param  array{owner: string, group: string, entries: array<string, int>}  $acl
     */
    private function planDefault(string $path, array $acl): void
    {
        $required = self::R | self::X;
        $current = $acl['entries']["default:user:{$this->user}"] ?? null;

        if ($current !== null && ($current & $required) === $required) {
            return;
        }

        // Never less than the user gets on the directory itself (by any route but ownership)
        // or than the existing default ACL would give it through a group or other.
        $hasDefault = array_filter(array_keys($acl['entries']), fn (string $key): bool => str_starts_with($key, 'default:')) !== [];
        $inherited = $this->effective($acl, '', ignoreOwner: true)
            | ($hasDefault ? $this->effective($acl, 'default:', ignoreOwner: true) : 0);

        $this->change('d:u', ($current ?? 0) | $inherited | $required, $path);
    }

    /**
     * POSIX ACL access check: owner, else named user, else the union of matching groups, else
     * other; named users and groups are limited by the mask.
     *
     * @param  array{owner: string, group: string, entries: array<string, int>}  $acl
     */
    private function effective(array $acl, string $scope, bool $ignoreOwner = false): int
    {
        $entries = $acl['entries'];
        $mask = $entries["{$scope}mask:"] ?? (self::R | self::W | self::X);

        if (! $ignoreOwner && $acl['owner'] === $this->user) {
            return $entries["{$scope}user:"] ?? 0;
        }

        if (isset($entries["{$scope}user:{$this->user}"])) {
            return $entries["{$scope}user:{$this->user}"] & $mask;
        }

        $matched = null;

        if (in_array($acl['group'], $this->groups, true) && isset($entries["{$scope}group:"])) {
            $matched = $entries["{$scope}group:"];
        }

        foreach ($this->groups as $group) {
            if (isset($entries["{$scope}group:{$group}"])) {
                $matched = ($matched ?? 0) | $entries["{$scope}group:{$group}"];
            }
        }

        if ($matched !== null) {
            return $matched & $mask;
        }

        return $entries["{$scope}other:"] ?? 0;
    }

    private function change(string $prefix, int $bits, string $path): void
    {
        $this->changes["{$prefix}:{$this->user}:".self::text($bits)][$path] = true;
    }

    private static function bits(string $text): int
    {
        return ($text[0] === 'r' ? self::R : 0) | ($text[1] === 'w' ? self::W : 0) | ($text[2] === 'x' ? self::X : 0);
    }

    private static function text(int $bits): string
    {
        return ($bits & self::R ? 'r' : '').($bits & self::W ? 'w' : '').($bits & self::X ? 'x' : '');
    }

    /**
     * getfacl writes whitespace and backslashes in names as \ooo.
     */
    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', fn (array $m): string => chr((int) octdec($m[1])), $value);
    }
}
