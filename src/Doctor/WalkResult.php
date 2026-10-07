<?php

namespace Jothamlec\OffsiteBackup\Doctor;

final class WalkResult
{
    /**
     * @param  list<string>  $missing  include paths that don't exist
     * @param  list<array{path: string, detail: string, directory: bool, excluded: bool}>  $unreadablePaths  every unreadable path that breaks backup:run (up to IncludeWalker::MAX_UNREADABLE), first one first
     */
    public function __construct(
        public readonly int $files = 0,
        public readonly int $bytes = 0,
        public readonly ?string $unreadable = null,
        public readonly ?string $unreadableDetail = null,
        public readonly ?string $skippedSymlink = null,
        public readonly array $missing = [],
        public readonly array $unreadablePaths = [],
    ) {}
}
