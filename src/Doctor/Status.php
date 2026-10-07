<?php

namespace Jothamlec\OffsiteBackup\Doctor;

enum Status: string
{
    case Pass = 'PASS';
    case Warn = 'WARN';
    case Fail = 'FAIL';

    public function weight(): int
    {
        return match ($this) {
            self::Pass => 0,
            self::Warn => 1,
            self::Fail => 2,
        };
    }

    public static function worst(Status ...$statuses): self
    {
        $worst = self::Pass;

        foreach ($statuses as $status) {
            if ($status->weight() > $worst->weight()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
