<?php

use Jothamlec\OffsiteBackup\Support\SchedulerTick;
use Jothamlec\OffsiteBackup\Support\SystemUser;

it('records the scheduler\'s run and user', function () {
    config(['offsite-backup.scheduler_tick_path' => $this->sandboxPath('ticks/tick.json')]);
    app()->forgetInstance(SchedulerTick::class);

    $this->artisan('offsite:heartbeat-tick')->assertSuccessful();

    $last = app(SchedulerTick::class)->last();

    expect($last)->not->toBeNull()
        ->and($last['user'])->toBe((new SystemUser)->current())
        ->and($last['at']->diffInSeconds(now(), true))->toBeLessThan(5);
});

it('reads nothing before the first tick', function () {
    expect((new SchedulerTick($this->sandboxPath('none.json')))->last())->toBeNull();
});
