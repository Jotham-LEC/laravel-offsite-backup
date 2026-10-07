<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\RunningUser;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Jothamlec\OffsiteBackup\Support\SchedulerTick;
use Jothamlec\OffsiteBackup\Support\SystemUser;

beforeEach(function () {
    app()->instance(SystemUser::class, new class extends SystemUser
    {
        public function current(): string
        {
            return 'deployer';
        }
    });
    app()->instance(SchedulerTick::class, new SchedulerTick($this->sandboxPath('tick.json')));
});

it('warns that the scheduler\'s user is unknown before the first tick', function () {
    $result = runCheck(RunningUser::class);

    expect($result->status)->toBe(Status::Warn)->and($result->message)->toContain('Running as deployer');
});

it('warns when the scheduler runs as someone else', function () {
    app(SchedulerTick::class)->record('www-data');

    $result = runCheck(RunningUser::class);

    expect($result->status)->toBe(Status::Warn)
        ->and($result->message)->toContain('the scheduler runs as www-data')
        ->and($result->hint)->toContain('sudo -u www-data php artisan offsite:doctor');
});

it('passes when both are the same user', function () {
    app(SchedulerTick::class)->record('deployer');

    expect(runCheck(RunningUser::class)->status)->toBe(Status::Pass);
});
