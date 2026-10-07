<?php

namespace Jothamlec\OffsiteBackup\Commands;

use Illuminate\Console\Command;
use Jothamlec\OffsiteBackup\Support\SchedulerTick;
use Jothamlec\OffsiteBackup\Support\SystemUser;

class HeartbeatTickCommand extends Command
{
    protected $signature = 'offsite:heartbeat-tick';

    protected $description = 'Records that the scheduler ran (for offsite:doctor)';

    public function handle(SchedulerTick $tick, SystemUser $user): int
    {
        $tick->record($user->current());

        return self::SUCCESS;
    }
}
