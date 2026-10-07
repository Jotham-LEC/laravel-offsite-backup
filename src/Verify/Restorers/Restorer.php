<?php

namespace Jothamlec\OffsiteBackup\Verify\Restorers;

use Jothamlec\OffsiteBackup\Verify\DumpFile;
use Jothamlec\OffsiteBackup\Verify\RestoredDatabase;

interface Restorer
{
    public function restore(DumpFile $dump, string $workDirectory): RestoredDatabase;
}
