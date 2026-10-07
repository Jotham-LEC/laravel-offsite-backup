<?php

use Illuminate\Support\Facades\Http;
use Jothamlec\OffsiteBackup\Support\Heartbeat;

it('builds Uptime Kuma URLs, replacing the push URL\'s own query', function () {
    $heartbeat = new Heartbeat('https://kuma.example.com/api/push/abc123?status=up&msg=OK&ping=', 'kuma');

    expect($heartbeat->successUrl())->toBe('https://kuma.example.com/api/push/abc123?status=up&msg=OK')
        ->and($heartbeat->failureUrl('backup:run failed'))->toBe('https://kuma.example.com/api/push/abc123?status=down&msg=backup%3Arun+failed');
});

it('builds healthchecks.io URLs', function () {
    $heartbeat = new Heartbeat('https://hc-ping.com/uuid/', 'healthchecks');

    expect($heartbeat->successUrl())->toBe('https://hc-ping.com/uuid/')
        ->and($heartbeat->failureUrl())->toBe('https://hc-ping.com/uuid/fail');
});

it('pings plain URLs on success only', function () {
    $heartbeat = new Heartbeat('https://ping.example.com/x', 'plain');

    expect($heartbeat->successUrl())->toBe('https://ping.example.com/x')
        ->and($heartbeat->failureUrl())->toBeNull();
});

it('is disabled without a URL', function () {
    $heartbeat = Heartbeat::fromConfig(['url' => '', 'format' => 'kuma']);

    expect($heartbeat->enabled())->toBeFalse()
        ->and($heartbeat->successUrl())->toBeNull()
        ->and($heartbeat->ping(true))->toBeFalse();
});

it('rejects unknown formats', function () {
    new Heartbeat('https://x', 'statuscake');
})->throws(InvalidArgumentException::class);

it('pings over HTTP', function () {
    Http::fake(['*' => Http::response('ok')]);

    expect((new Heartbeat('https://kuma.example.com/api/push/t', 'kuma'))->ping(false, 'verify failed'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://kuma.example.com/api/push/t?status=down&msg=verify+failed');
});
