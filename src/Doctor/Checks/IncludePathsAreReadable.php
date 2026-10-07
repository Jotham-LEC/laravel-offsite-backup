<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;

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

        if ($walk->unreadable !== null) {
            $results[] = CheckResult::fail(
                "Can't read {$walk->unreadable} ({$walk->unreadableDetail}).",
                config('backup.backup.source.files.ignore_unreadable_directories')
                    ? 'ignore_unreadable_directories is on, so unreadable directories are skipped silently. Fix the permissions or exclude the path.'
                    : 'backup:run will fail here. Give the backup user read access (chmod/chgrp or `setfacl -R -m u:<user>:rX`), or exclude the path.',
            );
        }

        foreach ($walk->missing as $path) {
            $results[] = CheckResult::warn("Include path {$path} doesn't exist; spatie skips it.", 'Create it or remove it from offsite-backup.include.');
        }

        if ($results === []) {
            return CheckResult::pass(sprintf('%d files readable.', $walk->files));
        }

        return CheckResult::combine($results);
    }
}
