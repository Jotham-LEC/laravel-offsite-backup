<?php

namespace Jothamlec\OffsiteBackup\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class Heartbeat
{
    public const FORMATS = ['kuma', 'healthchecks', 'plain'];

    public function __construct(
        public readonly ?string $url,
        public readonly string $format = 'kuma',
    ) {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Unknown heartbeat format '{$format}'. Use one of: ".implode(', ', self::FORMATS).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $config  ['url' => ..., 'format' => ...]
     */
    public static function fromConfig(array $config): self
    {
        $url = $config['url'] ?? null;
        $format = $config['format'] ?? 'kuma';

        return new self(is_string($url) && $url !== '' ? $url : null, is_string($format) ? $format : 'kuma');
    }

    public function enabled(): bool
    {
        return $this->url !== null;
    }

    public function successUrl(string $message = 'OK'): ?string
    {
        if ($this->url === null) {
            return null;
        }

        return match ($this->format) {
            'kuma' => $this->kuma('up', $message),
            default => $this->url,
        };
    }

    public function failureUrl(string $message = 'failed'): ?string
    {
        if ($this->url === null) {
            return null;
        }

        return match ($this->format) {
            'kuma' => $this->kuma('down', $message),
            'healthchecks' => rtrim($this->url, '/').'/fail',
            default => null,
        };
    }

    /**
     * Pings the success or failure URL. Returns false when there was nothing to ping or the ping failed.
     */
    public function ping(bool $ok, string $message = ''): bool
    {
        $url = $ok ? $this->successUrl($message ?: 'OK') : $this->failureUrl($message ?: 'failed');

        if ($url === null) {
            return false;
        }

        try {
            return Http::timeout(10)->retry(2, 1000, throw: false)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Uptime Kuma push URLs come with ?status=up&msg=OK&ping=; replace that query.
     */
    private function kuma(string $status, string $message): string
    {
        $base = strtok((string) $this->url, '?');

        return $base.'?'.http_build_query(['status' => $status, 'msg' => $message]);
    }
}
