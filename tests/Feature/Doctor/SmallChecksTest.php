<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\ConfigCacheIsFresh;
use Jothamlec\OffsiteBackup\Doctor\Checks\HeartbeatIsConfigured;
use Jothamlec\OffsiteBackup\Doctor\Checks\TimezoneIsExplicit;
use Jothamlec\OffsiteBackup\Doctor\Status;

it('passes when the config is not cached', function () {
    expect(runCheck(ConfigCacheIsFresh::class)->status)->toBe(Status::Pass);
});

it('warns when .env is newer than the config cache', function () {
    $cache = $this->sandboxPath('config-cache.php');
    $env = $this->sandboxPath('.env');
    file_put_contents($cache, '<?php return [];');
    file_put_contents($env, 'A=1');
    touch($cache, time() - 60);

    app()->instance(ConfigCacheIsFresh::class, new class($cache, $env) extends ConfigCacheIsFresh
    {
        public function __construct(private string $cache, private string $env) {}

        protected function cachedConfigPath(): string
        {
            return $this->cache;
        }

        protected function environmentFilePath(): string
        {
            return $this->env;
        }
    });

    $stale = runCheck(ConfigCacheIsFresh::class);
    touch($cache, time() + 60);
    clearstatcache();
    $fresh = runCheck(ConfigCacheIsFresh::class);

    expect($stale->status)->toBe(Status::Warn)
        ->and($stale->hint)->toContain('config:cache')
        ->and($fresh->status)->toBe(Status::Pass);
});

it('reports the schedule in an explicit timezone', function () {
    config(['offsite-backup.schedule.time' => '19:00']);

    $result = runCheck(TimezoneIsExplicit::class);

    expect($result->status)->toBe(Status::Pass)->and($result->message)->toBe('clean 19:00, run 19:05, monitor 20:00 UTC.');
});

it('warns when the schedule follows app.timezone', function () {
    config(['offsite-backup.schedule.timezone' => null, 'app.timezone' => 'Asia/Kuala_Lumpur']);

    $result = runCheck(TimezoneIsExplicit::class);

    expect($result->status)->toBe(Status::Warn)->and($result->message)->toContain('Asia/Kuala_Lumpur');
});

it('fails on an invalid timezone', function () {
    config(['offsite-backup.schedule.timezone' => 'Mars/Olympus']);

    expect(runCheck(TimezoneIsExplicit::class)->status)->toBe(Status::Fail);
});

it('warns without a heartbeat URL', function () {
    config(['offsite-backup.heartbeat.url' => null]);

    expect(runCheck(HeartbeatIsConfigured::class)->status)->toBe(Status::Warn);
});

it('passes with a heartbeat URL, without printing its token', function () {
    config(['offsite-backup.heartbeat' => ['url' => 'https://kuma.example.com/api/push/SECRET', 'format' => 'kuma']]);

    $result = runCheck(HeartbeatIsConfigured::class);

    expect($result->status)->toBe(Status::Pass)
        ->and($result->message)->toContain('kuma.example.com')
        ->and($result->message)->not->toContain('SECRET');
});

it('fails on an unknown heartbeat format', function () {
    config(['offsite-backup.heartbeat' => ['url' => 'https://x', 'format' => 'pager']]);

    expect(runCheck(HeartbeatIsConfigured::class)->status)->toBe(Status::Fail);
});
