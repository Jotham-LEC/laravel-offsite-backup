<?php

namespace Jothamlec\OffsiteBackup\Support;

use Carbon\CarbonImmutable;

/**
 * The scheduler's last run, written every minute by offsite:heartbeat-tick and read by offsite:doctor.
 */
final class SchedulerTick
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function record(string $user): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $payload = json_encode([
            'at' => CarbonImmutable::now()->toIso8601String(),
            'user' => $user,
            'hostname' => gethostname() ?: null,
        ], JSON_THROW_ON_ERROR);

        $temporary = $this->path.'.'.getmypid().'.tmp';
        file_put_contents($temporary, $payload);
        @chmod($temporary, 0664);
        rename($temporary, $this->path);
    }

    /**
     * @return array{at: CarbonImmutable, user: ?string}|null
     */
    public function last(): ?array
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);

        if (! is_array($data) || ! is_string($data['at'] ?? null)) {
            return null;
        }

        return [
            'at' => CarbonImmutable::parse($data['at']),
            'user' => is_string($data['user'] ?? null) ? $data['user'] : null,
        ];
    }
}
