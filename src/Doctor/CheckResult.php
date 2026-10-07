<?php

namespace Jothamlec\OffsiteBackup\Doctor;

final class CheckResult
{
    public function __construct(
        public readonly Status $status,
        public readonly string $message,
        public readonly ?string $hint = null,
    ) {}

    public static function pass(string $message): self
    {
        return new self(Status::Pass, $message);
    }

    public static function warn(string $message, ?string $hint = null): self
    {
        return new self(Status::Warn, $message, $hint);
    }

    public static function fail(string $message, ?string $hint = null): self
    {
        return new self(Status::Fail, $message, $hint);
    }

    /**
     * Combines several findings into one result with the worst status.
     *
     * @param  list<CheckResult>  $results
     */
    public static function combine(array $results, string $whenEmpty = 'OK'): self
    {
        if ($results === []) {
            return self::pass($whenEmpty);
        }

        $status = Status::worst(...array_map(fn (CheckResult $r): Status => $r->status, $results));
        $hints = array_values(array_unique(array_filter(array_map(fn (CheckResult $r): ?string => $r->hint, $results))));

        return new self(
            $status,
            implode("\n", array_map(fn (CheckResult $r): string => $r->message, $results)),
            $hints === [] ? null : implode("\n", $hints),
        );
    }
}
