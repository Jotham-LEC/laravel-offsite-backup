<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\SizeCap;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

beforeEach(function () {
    // ~1 MB per archive; the hardened retention keeps 42, so ~42 MB.
    file_put_contents($this->sandboxPath('shared/storage/app/big.bin'), str_repeat('a', 1048576));
});

it('counts the backups the retention keeps', function () {
    expect(SizeCap::backupsKept(config('backup.cleanup.default_strategy')))->toBe(42)
        ->and(SizeCap::backupsKept([]))->toBe(1);
});

it('passes without a size cap and with a roomy monitor', function () {
    $result = runCheck(SizeCap::class);

    expect($result->status)->toBe(Status::Pass)
        ->and($result->message)->toContain('x 42 kept = ~43 MB')
        ->and($result->message)->toContain('cap none, monitor 20000 MB');
});

it('fails when the size cap would prune kept backups', function () {
    config(['backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than' => 10]);

    $result = runCheck(SizeCap::class);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain('The size cap (10 MB) is below the projected storage');
});

it('warns when the monitor limit is below the projection', function () {
    config(['backup.monitor_backups.0.health_checks.'.MaximumStorageInMegabytes::class => 5]);

    expect(runCheck(SizeCap::class)->status)->toBe(Status::Warn);
});

it('warns when no monitor watches this backup', function () {
    config(['backup.monitor_backups' => [['name' => 'another-app', 'disks' => ['backups'], 'health_checks' => []]]]);

    $result = runCheck(SizeCap::class);

    expect($result->status)->toBe(Status::Warn)->and($result->message)->toContain("No monitor_backups entry for 'offsite-test'");
});
