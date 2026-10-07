<?php

namespace Jothamlec\OffsiteBackup\Doctor;

/**
 * One offsite:doctor check. Checks are resolved from the container, so they can type-hint
 * their dependencies, and listed in config('offsite-backup.doctor.checks').
 */
interface Check
{
    public function name(): string;

    public function run(DoctorContext $context): CheckResult;
}
