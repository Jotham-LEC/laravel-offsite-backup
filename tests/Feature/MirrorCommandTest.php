<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Jothamlec\OffsiteBackup\Scheduling\ScheduleRegistrar;

beforeEach(function () {
    config(['offsite-backup.name' => 'moojing-global.com', 'offsite-backup.mirror.source' => 'media']);
    Storage::fake('media');
    $this->media = Storage::disk('media');
    $this->backup = Storage::disk('backups');
});

it('copies missing and resized objects, skips unchanged ones and never deletes', function () {
    $this->media->put('media/new.png', 'new');
    $this->media->put('og-images/articles/resized.png', 'twelve bytes');
    $this->media->put('media/same.pdf', 'same');
    // The layout the hand-rolled backup:mirror-media already wrote: <name>/media/<source path>.
    $this->backup->put('moojing-global.com/media/og-images/articles/resized.png', 'short');
    $this->backup->put('moojing-global.com/media/media/same.pdf', 'same');
    $this->backup->put('moojing-global.com/media/media/deleted-from-source.png', 'kept');

    $this->artisan('offsite:mirror')
        ->expectsOutput('Mirroring media:/ to backups:moojing-global.com/media/')
        ->expectsOutput('Copied: 2')
        ->expectsOutput('Already backed up: 1')
        ->expectsOutput('Failed: 0')
        ->assertExitCode(0);

    expect($this->backup->get('moojing-global.com/media/media/new.png'))->toBe('new')
        ->and($this->backup->get('moojing-global.com/media/og-images/articles/resized.png'))->toBe('twelve bytes')
        ->and($this->backup->get('moojing-global.com/media/media/deleted-from-source.png'))->toBe('kept');
});

it('is idempotent', function () {
    $this->media->put('media/a.png', 'a');

    $this->artisan('offsite:mirror')->expectsOutput('Copied: 1')->assertExitCode(0);
    $this->artisan('offsite:mirror')->expectsOutput('Copied: 0')->expectsOutput('Already backed up: 1')->assertExitCode(0);
});

it('limits the copy to a prefix but keeps the full path', function () {
    $this->media->put('media/a.png', 'a');
    $this->media->put('private/b.txt', 'b');

    $this->artisan('offsite:mirror', ['--prefix' => 'media/'])->expectsOutput('Copied: 1')->assertExitCode(0);

    expect($this->backup->exists('moojing-global.com/media/media/a.png'))->toBeTrue()
        ->and($this->backup->exists('moojing-global.com/media/private/b.txt'))->toBeFalse();
});

it('copies nothing on a dry run', function () {
    $this->media->put('media/a.png', 'a');

    $this->artisan('offsite:mirror', ['--dry-run' => true])->expectsOutput('Would copy: 1')->assertExitCode(0);

    expect($this->backup->allFiles())->toBe([]);
});

it('carries on past a failed object and exits non-zero', function () {
    $this->media->put('media/unreadable.png', 'locked');
    $this->media->put('media/fine.png', 'fine');
    chmod($this->media->path('media/unreadable.png'), 0000);

    try {
        $this->artisan('offsite:mirror')
            ->expectsOutput('Copied: 1')
            ->expectsOutput('Failed: 1')
            ->assertExitCode(1);
    } finally {
        chmod($this->media->path('media/unreadable.png'), 0644);
    }

    expect($this->backup->exists('moojing-global.com/media/media/fine.png'))->toBeTrue();
})->skip(fn () => runningAsRoot(), 'root reads everything');

it('refuses without a source, or with the backup disk as the source', function () {
    config(['offsite-backup.mirror.source' => null]);
    $this->artisan('offsite:mirror')->assertExitCode(2);

    $this->artisan('offsite:mirror', ['--source' => 'backups'])->assertExitCode(2);
});

it('fails when the source can\'t be listed', function () {
    config(['filesystems.disks.broken' => ['driver' => 'nope']]);

    $this->artisan('offsite:mirror', ['--source' => 'broken'])->expectsOutput('Copied: 0')->assertExitCode(1);
});

it('is scheduled when enabled, 15 minutes after backup:run, pinging the heartbeat only on failure', function () {
    $config = [
        'name' => 'shop',
        'schedule' => ['enabled' => true, 'time' => '19:00', 'timezone' => 'UTC', 'environments' => ['production']],
        'heartbeat' => ['url' => 'https://kuma.example.com/api/push/t', 'format' => 'kuma'],
        'mirror' => ['enabled' => true, 'source' => 'media', 'time' => 'auto'],
    ];
    $schedule = new Schedule;
    (new ScheduleRegistrar($config))->register($schedule);
    $mirror = collect($schedule->events())->first(fn (Event $e) => str_contains((string) $e->command, 'offsite:mirror'));

    expect($mirror)->not->toBeNull()
        ->and($mirror->expression)->toBe('20 19 * * *')
        ->and($mirror->withoutOverlapping)->toBeTrue()
        ->and($mirror->environments)->toBe(['production'])
        ->and((new ReflectionProperty(Event::class, 'afterCallbacks'))->getValue($mirror))->toHaveCount(1);

    $fixed = new Schedule;
    (new ScheduleRegistrar(array_replace_recursive($config, ['mirror' => ['time' => '04:40']])))->register($fixed);
    expect(collect($fixed->events())->first(fn (Event $e) => str_contains((string) $e->command, 'offsite:mirror'))->expression)->toBe('40 4 * * *');

    foreach ([['enabled' => false], ['source' => null]] as $off) {
        $none = new Schedule;
        (new ScheduleRegistrar(array_replace_recursive($config, ['mirror' => $off])))->register($none);
        expect(collect($none->events())->contains(fn (Event $e) => str_contains((string) $e->command, 'offsite:mirror')))->toBeFalse();
    }
});
