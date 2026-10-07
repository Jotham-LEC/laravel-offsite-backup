<?php

namespace Jothamlec\OffsiteBackup\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Jothamlec\OffsiteBackup\Support\Heartbeat;
use Jothamlec\OffsiteBackup\Support\ScheduleTime;

final class ScheduleRegistrar
{
    /**
     * @param  array<string, mixed>  $config  config('offsite-backup')
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return array{clean: string, run: string, monitor: string, mirror: string}
     */
    public function times(): array
    {
        /** @var array<string, mixed> $schedule */
        $schedule = (array) ($this->config['schedule'] ?? []);
        $window = is_array($schedule['window'] ?? null) ? array_values($schedule['window']) : ['01:00', '05:00'];

        $clean = ScheduleTime::resolve((string) ($schedule['time'] ?? 'auto'), (string) ($this->config['name'] ?? 'laravel'), $window);

        /** @var array<string, mixed> $mirror */
        $mirror = (array) ($this->config['mirror'] ?? []);
        $mirrorTime = is_string($mirror['time'] ?? null) && $mirror['time'] !== '' && $mirror['time'] !== 'auto'
            ? ScheduleTime::resolve($mirror['time'], '')
            : ScheduleTime::after($clean, 20);

        $monitorTime = $schedule['monitor_time'] ?? null;

        return [
            'clean' => $clean,
            'run' => ScheduleTime::after($clean, 5),
            'monitor' => is_string($monitorTime) && $monitorTime !== '' && $monitorTime !== 'auto'
                ? ScheduleTime::resolve($monitorTime, '')
                : ScheduleTime::after($clean, 60),
            'mirror' => $mirrorTime,
        ];
    }

    public function mirrorEnabled(): bool
    {
        /** @var array<string, mixed> $mirror */
        $mirror = (array) ($this->config['mirror'] ?? []);

        return (bool) ($mirror['enabled'] ?? false) && is_string($mirror['source'] ?? null) && $mirror['source'] !== '';
    }

    public function register(Schedule $schedule): void
    {
        /** @var array<string, mixed> $settings */
        $settings = (array) ($this->config['schedule'] ?? []);

        if (! ($settings['enabled'] ?? false)) {
            return;
        }

        $timezone = is_string($settings['timezone'] ?? null) ? $settings['timezone'] : null;
        $environments = array_values(array_filter((array) ($settings['environments'] ?? []), 'is_string'));
        $times = $this->times();
        $heartbeat = Heartbeat::fromConfig((array) ($this->config['heartbeat'] ?? []));

        $constrain = function (Event $event) use ($timezone, $environments): Event {
            if ($timezone !== null) {
                $event->timezone($timezone);
            }

            if ($environments !== []) {
                $event->environments($environments);
            }

            return $event;
        };

        if ($settings['clean'] ?? true) {
            $constrain($schedule->command('backup:clean')->dailyAt($times['clean']))->withoutOverlapping();
        }

        $run = $constrain($schedule->command('backup:run')->dailyAt($times['run']))->withoutOverlapping();

        if (($success = $heartbeat->successUrl('OK')) !== null) {
            $run->pingOnSuccess($success);
        }

        if (($failure = $heartbeat->failureUrl('backup:run failed')) !== null) {
            $run->pingOnFailure($failure);
        }

        $constrain($schedule->command('backup:monitor')->dailyAt($times['monitor']))->withoutOverlapping();

        if ($this->mirrorEnabled()) {
            $mirror = $constrain($schedule->command('offsite:mirror')->dailyAt($times['mirror']))->withoutOverlapping(360);

            if (($failure = $heartbeat->failureUrl('offsite:mirror failed')) !== null) {
                $mirror->pingOnFailure($failure);
            }
        }

        $constrain($schedule->command('offsite:heartbeat-tick')->everyMinute());
    }
}
