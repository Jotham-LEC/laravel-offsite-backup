<?php

namespace Jothamlec\OffsiteBackup\Doctor;

final class DoctorContext
{
    private ?WalkResult $walk = null;

    public function __construct(
        public readonly bool $writeProbe = false,
    ) {}

    /**
     * The include paths walked once, shared by the checks that need it.
     */
    public function walk(): WalkResult
    {
        return $this->walk ??= IncludeWalker::fromBackupConfig()->walk();
    }
}
