<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\SchedulerIsAlive;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Jothamlec\OffsiteBackup\Support\SchedulerTick;

beforeEach(function () {
    config(['offsite-backup.schedule.environments' => ['testing']]);
    app()->instance(SchedulerTick::class, new SchedulerTick($this->sandboxPath('tick.json')));
});

it('warns when the scheduler has never ticked', function () {
    $result = runCheck(SchedulerIsAlive::class);

    expect($result->status)->toBe(Status::Warn)
        ->and($result->message)->toContain('never run')
        ->and($result->hint)->toContain('dep offsite:scheduler');
});

it('passes on a tick within two minutes', function () {
    app(SchedulerTick::class)->record('deployer');
    $this->travel(90)->seconds();

    $result = runCheck(SchedulerIsAlive::class);

    expect($result->status)->toBe(Status::Pass)->and($result->message)->toContain('as deployer');
});

it('fails on a stale tick', function () {
    app(SchedulerTick::class)->record('deployer');
    $this->travel(3)->minutes();

    $result = runCheck(SchedulerIsAlive::class);

    expect($result->status)->toBe(Status::Fail)->and($result->hint)->toContain('crontab -l');
});

it('warns when this environment schedules nothing', function () {
    config(['offsite-backup.schedule.environments' => ['production']]);

    $result = runCheck(SchedulerIsAlive::class);

    expect($result->status)->toBe(Status::Warn)->and($result->message)->toContain("APP_ENV 'testing'");
});

it('warns when scheduling is off', function () {
    config(['offsite-backup.schedule.enabled' => false]);

    expect(runCheck(SchedulerIsAlive::class)->status)->toBe(Status::Warn);
});
