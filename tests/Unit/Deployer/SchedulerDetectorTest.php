<?php

use Jothamlec\OffsiteBackup\Deployer\SchedulerDetector;

$dump = <<<'OUT'
==> /etc/cron.d/shop-scheduler
# Laravel's scheduler for shop
* * * * * deployer cd /home/deployer/shop/current && php artisan schedule:run >> /dev/null 2>&1
==> /etc/cron.d/other-app
* * * * * deployer cd /home/deployer/other-app/current && php artisan schedule:run
==> /etc/systemd/system/laravel-schedule.service
[Service]
User=www-data
WorkingDirectory=/home/deployer/blog/current
ExecStart=/usr/bin/php artisan schedule:run
OUT;

it('parses the gathered files', function () use ($dump) {
    $files = SchedulerDetector::parse($dump);

    expect(array_keys($files))->toBe([
        '/etc/cron.d/shop-scheduler',
        '/etc/cron.d/other-app',
        '/etc/systemd/system/laravel-schedule.service',
    ])->and($files['/etc/cron.d/other-app'])->toContain('other-app/current');
});

it('finds a cron.d entry for this app only', function () use ($dump) {
    $found = SchedulerDetector::find(SchedulerDetector::parse($dump), ['/home/deployer/shop']);

    expect($found)->toHaveCount(1)
        ->and($found[0])->toStartWith('/etc/cron.d/shop-scheduler: * * * * * deployer cd /home/deployer/shop/current');
});

it('finds a systemd unit by its working directory', function () use ($dump) {
    expect(SchedulerDetector::find(SchedulerDetector::parse($dump), ['/home/deployer/blog']))
        ->toBe(['systemd unit /etc/systemd/system/laravel-schedule.service']);
});

it('does not match a path that merely shares a prefix', function () use ($dump) {
    expect(SchedulerDetector::find(SchedulerDetector::parse($dump), ['/home/deployer/sho']))->toBe([]);
});

it('ignores commented-out lines', function () {
    $files = ['/etc/cron.d/app' => "# * * * * * cd /srv/app/current && php artisan schedule:run\n"];

    expect(SchedulerDetector::find($files, ['/srv/app']))->toBe([]);
});

it('reports crontab lines that are not ours', function () {
    $marker = '# shop scheduler (managed by dep offsite:scheduler)';
    $crontab = implode("\n", [
        "* * * * * cd '/srv/shop/current' && php artisan schedule:run >> /dev/null 2>&1 {$marker}",
        '* * * * * cd /srv/shop/current && php artisan schedule:run',
        '0 3 * * * /usr/local/bin/something-else',
    ]);

    expect(SchedulerDetector::foreignCrontabLines($crontab, ['/srv/shop'], $marker))
        ->toBe(['crontab: * * * * * cd /srv/shop/current && php artisan schedule:run']);
});

it('renders the crontab line, with an optional umask', function () {
    expect(SchedulerDetector::crontabLine('/srv/shop/current', '/usr/bin/php8.4', '# m'))
        ->toBe("* * * * * cd '/srv/shop/current' && /usr/bin/php8.4 artisan schedule:run >> /dev/null 2>&1 # m")
        ->and(SchedulerDetector::crontabLine('/srv/shop/current', 'php', '# m', '002'))
        ->toStartWith("* * * * * umask 002 && cd '/srv/shop/current'");
});

it('gathers with a command that always succeeds', function () {
    exec(SchedulerDetector::gatherCommand(), $output, $exit);

    expect($exit)->toBe(0);
});
