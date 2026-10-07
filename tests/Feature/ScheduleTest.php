<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Jothamlec\OffsiteBackup\Scheduling\ScheduleRegistrar;
use Jothamlec\OffsiteBackup\Support\ScheduleTime;

/**
 * @return array<string, Event>
 */
function offsiteEvents(array $config): array
{
    $schedule = new Schedule;
    (new ScheduleRegistrar($config))->register($schedule);

    $events = [];

    foreach ($schedule->events() as $event) {
        preg_match("/artisan'?\s+(\S+)/", (string) $event->command, $m);
        $events[$m[1] ?? (string) $event->command] = $event;
    }

    return $events;
}

function scheduleConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'shop',
        'schedule' => ['enabled' => true, 'time' => '19:00', 'window' => ['01:00', '05:00'], 'timezone' => 'UTC', 'environments' => ['production']],
        'heartbeat' => ['url' => 'https://kuma.example.com/api/push/t?status=up&msg=OK&ping=', 'format' => 'kuma'],
    ], $overrides);
}

it('schedules clean, run, monitor and the heartbeat tick', function () {
    $events = offsiteEvents(scheduleConfig());

    expect(array_keys($events))->toBe(['backup:clean', 'backup:run', 'backup:monitor', 'offsite:heartbeat-tick'])
        ->and($events['backup:clean']->expression)->toBe('0 19 * * *')
        ->and($events['backup:run']->expression)->toBe('5 19 * * *')
        ->and($events['backup:monitor']->expression)->toBe('0 20 * * *')
        ->and($events['offsite:heartbeat-tick']->expression)->toBe('* * * * *');

    foreach ($events as $event) {
        expect($event->timezone)->toBe('UTC')
            ->and($event->environments)->toBe(['production']);
    }

    expect($events['backup:run']->withoutOverlapping)->toBeTrue()
        ->and($events['backup:clean']->withoutOverlapping)->toBeTrue();
});

it('pings the heartbeat on success and on failure', function () {
    $run = offsiteEvents(scheduleConfig())['backup:run'];
    $reflection = new ReflectionProperty(Event::class, 'afterCallbacks');
    $callbacks = $reflection->getValue($run);

    expect($callbacks)->toHaveCount(2);

    // Laravel pings with a Guzzle client from the container; record what it sends.
    $history = [];
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(200))));
    $stack->push(Middleware::history($history));
    app()->instance(Client::class, new Client(['handler' => $stack]));

    foreach ([0, 1] as $exitCode) {
        $run->exitCode = $exitCode;

        foreach ($callbacks as $callback) {
            app()->call($callback);
        }
    }

    expect(array_map(fn (array $entry): string => (string) $entry['request']->getUri(), $history))->toBe([
        'https://kuma.example.com/api/push/t?status=up&msg=OK',
        'https://kuma.example.com/api/push/t?status=down&msg=backup%3Arun+failed',
    ]);
})->skip(fn () => ! class_exists(Client::class), 'needs guzzle');

it('derives the time from the name with time "auto"', function () {
    $a = (new ScheduleRegistrar(scheduleConfig(['schedule' => ['time' => 'auto'], 'name' => 'shop'])))->times();
    $b = (new ScheduleRegistrar(scheduleConfig(['schedule' => ['time' => 'auto'], 'name' => 'shop'])))->times();

    expect($a)->toBe($b)
        ->and(ScheduleTime::minutes($a['clean']))->toBeGreaterThanOrEqual(60)->toBeLessThan(300);
});

it('runs backup:monitor at schedule.monitor_time when set', function () {
    expect(offsiteEvents(scheduleConfig(['schedule' => ['monitor_time' => '06:30']]))['backup:monitor']->expression)->toBe('30 6 * * *')
        ->and(offsiteEvents(scheduleConfig(['schedule' => ['monitor_time' => 'auto']]))['backup:monitor']->expression)->toBe('0 20 * * *');
});

it('leaves backup:clean out for Object Lock buckets', function () {
    expect(array_keys(offsiteEvents(scheduleConfig(['schedule' => ['clean' => false]]))))
        ->toBe(['backup:run', 'backup:monitor', 'offsite:heartbeat-tick']);
});

it('schedules nothing when disabled', function () {
    expect(offsiteEvents(scheduleConfig(['schedule' => ['enabled' => false]])))->toBe([]);
});

it('registers on the application schedule through the service provider', function () {
    config(['offsite-backup.schedule.time' => '02:00']);

    $commands = collect(app(Schedule::class)->events())->map(fn (Event $e) => (string) $e->command)->implode("\n");

    expect($commands)->toContain('backup:run')->toContain('offsite:heartbeat-tick');
});
