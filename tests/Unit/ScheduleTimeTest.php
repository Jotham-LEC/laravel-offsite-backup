<?php

use Jothamlec\OffsiteBackup\Support\ScheduleTime;

it('keeps an explicit time', function () {
    expect(ScheduleTime::resolve('19:05', 'app'))->toBe('19:05')
        ->and(ScheduleTime::resolve('7:00', 'app'))->toBe('07:00');
});

it('derives a stable time on a 5-minute boundary inside the window', function () {
    $times = [];

    foreach (['shop', 'blog', 'crm', 'wiki', 'portal'] as $name) {
        $time = ScheduleTime::resolve('auto', $name, ['01:00', '05:00']);
        $minutes = ScheduleTime::minutes($time);

        expect($time)->toBe(ScheduleTime::resolve('auto', $name, ['01:00', '05:00']))
            ->and($minutes)->toBeGreaterThanOrEqual(60)->toBeLessThan(300)
            ->and($minutes % 5)->toBe(0);

        $times[] = $time;
    }

    expect(count(array_unique($times)))->toBeGreaterThan(1);
});

it('handles a window across midnight', function () {
    $minutes = ScheduleTime::minutes(ScheduleTime::resolve('auto', 'app', ['23:00', '01:00']));

    expect($minutes >= 23 * 60 || $minutes < 60)->toBeTrue();
});

it('adds minutes across midnight', function () {
    expect(ScheduleTime::after('23:30', 60))->toBe('00:30')
        ->and(ScheduleTime::after('01:00', 5))->toBe('01:05');
});

it('rejects invalid times', function () {
    ScheduleTime::resolve('25:00', 'app');
})->throws(InvalidArgumentException::class);
