<?php

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\Checks\HeartbeatIsConfigured;
use Jothamlec\OffsiteBackup\Doctor\Checks\TimezoneIsExplicit;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;

class AlwaysFails implements Check
{
    public function name(): string
    {
        return 'Always fails';
    }

    public function run(DoctorContext $context): CheckResult
    {
        return CheckResult::fail('broken on purpose', 'fix it');
    }
}

class Explodes implements Check
{
    public function name(): string
    {
        return 'Explodes';
    }

    public function run(DoctorContext $context): CheckResult
    {
        throw new RuntimeException('kaboom');
    }
}

it('runs the configured checks and succeeds without failures', function () {
    config(['offsite-backup.doctor.checks' => [TimezoneIsExplicit::class, HeartbeatIsConfigured::class]]);

    $this->artisan('offsite:doctor')
        ->expectsOutputToContain('1 passed, 1 warnings, 0 failed.')
        ->assertSuccessful();
});

it('exits non-zero when a check fails', function () {
    config(['offsite-backup.doctor.checks' => [TimezoneIsExplicit::class, AlwaysFails::class]]);

    $this->artisan('offsite:doctor')->assertFailed();
});

it('turns a throwing check or a bad class into a failure', function () {
    config(['offsite-backup.doctor.checks' => [Explodes::class, stdClass::class]]);

    $this->artisan('offsite:doctor --json')->assertFailed();

    Artisan::call('offsite:doctor', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($json['status'])->toBe('FAIL')
        ->and($json['checks'][0]['message'])->toBe('The check threw: kaboom')
        ->and($json['checks'][1]['message'])->toContain('Not an');
});

it('prints JSON', function () {
    config(['offsite-backup.doctor.checks' => [TimezoneIsExplicit::class]]);

    Artisan::call('offsite:doctor', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($json)->toMatchArray(['status' => 'PASS'])
        ->and($json['checks'][0])->toMatchArray(['check' => 'Schedule timezone', 'status' => 'PASS']);
});

it('runs the full default list against the sandbox', function () {
    Artisan::call('offsite:doctor', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect($json['checks'])->toHaveCount(11)
        ->and(array_column($json['checks'], 'status', 'check'))->toMatchArray([
            'Include paths readable' => 'PASS',
            'Paths and symlinks' => 'PASS',
            'Disk reachable' => 'PASS',
            'Config cache' => 'PASS',
            'Schedule timezone' => 'PASS',
        ]);
});
