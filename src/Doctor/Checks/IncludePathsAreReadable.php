<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Doctor\IncludeWalker;

class IncludePathsAreReadable implements Check
{
    public function name(): string
    {
        return 'Include paths readable';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $walk = $context->walk();
        $results = [];

        if ($walk->unreadablePaths !== []) {
            $results[] = $this->unreadable($walk->unreadablePaths);
        }

        foreach ($walk->missing as $path) {
            $results[] = CheckResult::warn("Include path {$path} doesn't exist; spatie skips it.", 'Create it or remove it from offsite-backup.include.');
        }

        if ($results === []) {
            return CheckResult::pass(sprintf('%d files readable.', $walk->files));
        }

        return CheckResult::combine($results);
    }

    /**
     * @param  non-empty-list<array{path: string, detail: string, directory: bool, excluded: bool}>  $paths
     */
    private function unreadable(array $paths): CheckResult
    {
        $lines = array_map(fn (array $p): string => "Can't read {$p['path']} ({$p['detail']})"
            .($p['excluded'] ? ', which is excluded, but spatie walks into excluded directories before filtering' : '').'.', $paths);

        if (count($paths) === IncludeWalker::MAX_UNREADABLE) {
            $lines[] = 'Stopped listing after '.IncludeWalker::MAX_UNREADABLE.'.';
        }

        $directories = array_filter($paths, fn (array $p): bool => $p['directory']);
        $hints = [];

        if (config('backup.backup.source.files.ignore_unreadable_directories')) {
            $hints[] = 'ignore_unreadable_directories is on, so unreadable directories are skipped silently and their files are missing from the backups.';
        } else {
            $hints[] = 'backup:run will fail here.';
        }

        $hints[] = 'Give the backup user read access (chmod/chgrp, or `setfacl -R -m u:<user>:rX <path>` plus a default ACL), or remove the path.';

        if ($directories !== []) {
            $hints[] = 'Excluding a directory does not help: spatie\'s Finder still opens it.';
        }

        return CheckResult::fail(implode("\n", $lines), implode(' ', $hints));
    }
}
