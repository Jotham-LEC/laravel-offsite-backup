<?php

namespace Jothamlec\OffsiteBackup\Support;

use InvalidArgumentException;

final class ScheduleTime
{
    /**
     * 'HH:MM' as is, or 'auto': a time within the window derived from a hash of the name, on a
     * 5-minute boundary, so many apps sharing a bucket or a box don't all start at once.
     *
     * @param  array{0: string, 1: string}|array<int, string>  $window
     */
    public static function resolve(string $time, string $name, array $window = ['01:00', '05:00']): string
    {
        if ($time !== 'auto') {
            return self::format(self::minutes($time));
        }

        $start = self::minutes($window[0] ?? '01:00');
        $end = self::minutes($window[1] ?? '05:00');
        $span = ($end - $start + 1440) % 1440 ?: 1440;
        $slots = max(1, intdiv($span, 5));

        return self::format($start + (crc32($name) % $slots) * 5);
    }

    /**
     * The time $minutes after 'HH:MM', wrapping past midnight.
     */
    public static function after(string $time, int $minutes): string
    {
        return self::format(self::minutes($time) + $minutes);
    }

    public static function minutes(string $time): int
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            throw new InvalidArgumentException("Invalid time '{$time}'; use HH:MM or 'auto'.");
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    private static function format(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
