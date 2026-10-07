<?php

namespace Jothamlec\OffsiteBackup\Doctor;

final class WalkResult
{
    /**
     * @param  list<string>  $missing  include paths that don't exist
     */
    public function __construct(
        public readonly int $files = 0,
        public readonly int $bytes = 0,
        public readonly ?string $unreadable = null,
        public readonly ?string $unreadableDetail = null,
        public readonly ?string $skippedSymlink = null,
        public readonly array $missing = [],
    ) {}
}
