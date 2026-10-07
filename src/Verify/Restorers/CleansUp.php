<?php

namespace Jothamlec\OffsiteBackup\Verify\Restorers;

/**
 * A restorer holding something to release once its database has been checked (a container).
 */
interface CleansUp
{
    public function cleanup(): void;
}
