<?php

namespace Jothamlec\OffsiteBackup\Verify;

final class VerifyOptions
{
    /**
     * @param  list<string>  $expectedConnections
     * @param  list<string>  $expectedPaths
     * @param  list<mixed>  $healthChecks  [class, options] pairs
     * @param  list<string>  $ignoreRestoreErrors
     */
    public function __construct(
        public readonly string $disk,
        public readonly string $name,
        public readonly ?string $password,
        public readonly ?string $backup = null,
        public readonly bool $keep = false,
        public readonly ?string $scratchConnection = null,
        public readonly array $expectedConnections = [],
        public readonly int $minimumTables = 1,
        public readonly int $maximumAgeHours = 26,
        public readonly int $minimumFiles = 1,
        public readonly array $expectedPaths = [],
        public readonly array $healthChecks = [],
        public readonly array $ignoreRestoreErrors = [],
    ) {}
}
