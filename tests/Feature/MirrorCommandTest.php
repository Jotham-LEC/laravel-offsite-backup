<?php

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Jothamlec\OffsiteBackup\Scheduling\ScheduleRegistrar;
use League\Flysystem\Filesystem;

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
    $this->backup->put('moojing-global.com-media/og-images/articles/resized.png', 'short');
    $this->backup->put('moojing-global.com-media/media/same.pdf', 'same');
    $this->backup->put('moojing-global.com-media/media/deleted-from-source.png', 'kept');

    $this->artisan('offsite:mirror')
        ->expectsOutput('Mirroring media:/ to backups:moojing-global.com-media/')
        ->expectsOutput('Copied: 2')
        ->expectsOutput('Already backed up: 1')
        ->expectsOutput('Failed: 0')
        ->assertExitCode(0);

    expect($this->backup->get('moojing-global.com-media/media/new.png'))->toBe('new')
        ->and($this->backup->get('moojing-global.com-media/og-images/articles/resized.png'))->toBe('twelve bytes')
        ->and($this->backup->get('moojing-global.com-media/media/deleted-from-source.png'))->toBe('kept');
});

it('mirrors into mirror.destination, warning when it is inside the backup folder', function () {
    $this->media->put('media/a.png', 'a');
    config(['offsite-backup.mirror.destination' => '/moojing-global.com/media/']);

    $this->artisan('offsite:mirror')
        ->expectsOutput('Mirroring media:/ to backups:moojing-global.com/media/')
        ->expectsOutputToContain('is inside the backup folder moojing-global.com/')
        ->expectsOutput('Copied: 1')
        ->assertExitCode(0);

    expect($this->backup->get('moojing-global.com/media/media/a.png'))->toBe('a');

    config(['offsite-backup.mirror.destination' => 'mirrors/moojing']);
    $this->artisan('offsite:mirror')->doesntExpectOutputToContain('inside the backup folder')->assertExitCode(0);

    expect($this->backup->get('mirrors/moojing/media/a.png'))->toBe('a');
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

    expect($this->backup->exists('moojing-global.com-media/media/a.png'))->toBeTrue()
        ->and($this->backup->exists('moojing-global.com-media/private/b.txt'))->toBeFalse();
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

    expect($this->backup->exists('moojing-global.com-media/media/fine.png'))->toBeTrue();
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

it('relocates a v0.2.x mirror out of the backup folder: copies, checks sizes, deletes the old keys', function () {
    $this->backup->put('moojing-global.com/2026-10-01-03-00-00.zip', 'zip');
    $this->backup->put('moojing-global.com/media/media/a.png', 'aaa');
    $this->backup->put('moojing-global.com/media/og-images/b.png', 'bb');
    // Already copied by an interrupted earlier run.
    $this->backup->put('moojing-global.com-media/media/c.png', 'c');
    $this->backup->put('moojing-global.com/media/media/c.png', 'c');

    $this->artisan('offsite:mirror', ['--relocate-from' => 'moojing-global.com/media/'])
        ->expectsOutput('Relocating backups:moojing-global.com/media/ to backups:moojing-global.com-media/')
        ->expectsOutput('Moved: 2')
        ->expectsOutput('Already at the destination (old key deleted): 1')
        ->expectsOutput('Failed: 0')
        ->assertExitCode(0);

    expect($this->backup->allFiles('moojing-global.com'))->toBe(['moojing-global.com/2026-10-01-03-00-00.zip'])
        ->and($this->backup->get('moojing-global.com-media/media/a.png'))->toBe('aaa')
        ->and($this->backup->get('moojing-global.com-media/og-images/b.png'))->toBe('bb')
        ->and($this->backup->get('moojing-global.com-media/media/c.png'))->toBe('c');

    // Resumable and idempotent: nothing left to do, and a normal mirror finds everything in place.
    $this->artisan('offsite:mirror', ['--relocate-from' => 'moojing-global.com/media'])
        ->expectsOutput('Moved: 0')
        ->expectsOutput('Nothing left under moojing-global.com/media/.')
        ->assertExitCode(0);

    $this->media->put('media/a.png', 'aaa');
    $this->artisan('offsite:mirror')->expectsOutput('Copied: 0')->expectsOutput('Already backed up: 1')->assertExitCode(0);
});

it('relocates nothing on a dry run', function () {
    $this->backup->put('moojing-global.com/media/media/a.png', 'aaa');
    $this->backup->put('moojing-global.com/media/media/b.png', 'b');
    $this->backup->put('moojing-global.com-media/media/b.png', 'b');

    $this->artisan('offsite:mirror', ['--relocate-from' => 'moojing-global.com/media', '--dry-run' => true])
        ->expectsOutput('Relocating backups:moojing-global.com/media/ to backups:moojing-global.com-media/ (dry run)')
        ->expectsOutput('Would move: 1')
        ->expectsOutput('Already at the destination (old key would be deleted): 1')
        ->assertExitCode(0);

    expect($this->backup->allFiles())->toHaveCount(3);
});

it('overwrites a partial copy and keeps the old key when the copy fails', function () {
    $this->backup->put('moojing-global.com/media/media/a.png', 'complete');
    $this->backup->put('moojing-global.com-media/media/a.png', 'part');
    $this->backup->put('moojing-global.com/media/media/locked.png', 'x');
    mkdir($this->backup->path('moojing-global.com-media/media/locked.png'), 0777, true);

    $this->artisan('offsite:mirror', ['--relocate-from' => 'moojing-global.com/media'])
        ->expectsOutput('Moved: 1')
        ->expectsOutput('Failed: 1')
        ->assertExitCode(1);

    expect($this->backup->get('moojing-global.com-media/media/a.png'))->toBe('complete')
        ->and($this->backup->exists('moojing-global.com/media/media/a.png'))->toBeFalse()
        ->and($this->backup->get('moojing-global.com/media/media/locked.png'))->toBe('x');
});

it('refuses to relocate the backups themselves or into an overlapping prefix', function () {
    $this->backup->put('moojing-global.com/2026-10-01-03-00-00.zip', 'zip');

    $this->artisan('offsite:mirror', ['--relocate-from' => 'moojing-global.com'])->assertExitCode(2);
    $this->artisan('offsite:mirror', ['--relocate-from' => '/'])->assertExitCode(2);
    $this->artisan('offsite:mirror', ['--relocate-from' => 'moojing-global.com-media/old'])->assertExitCode(2);

    expect($this->backup->exists('moojing-global.com/2026-10-01-03-00-00.zip'))->toBeTrue();
});

it('relocates on S3 with CopyObject, a HEAD and a DeleteObject per key: no download, no ACL read', function () {
    $commands = [];
    $mock = new MockHandler;
    $listing = fn (string $prefix, array $keys) => new Result(['KeyCount' => count($keys), 'Contents' => array_map(
        fn (string $key) => ['Key' => $prefix.$key, 'Size' => 3, 'LastModified' => new DateTimeImmutable],
        $keys,
    )]);
    $mock->append(
        $listing('moojing-global.com/media/', ['media/a.png']),
        $listing('moojing-global.com-media/', []),
    );
    // ObjectCopier HEADs the source for its size, then copies server-side; then the size check and the delete.
    $mock->append(new Result(['ContentLength' => 3]), new Result([]), new Result(['ContentLength' => 3]), new Result([]));

    $client = new S3Client(['region' => 'us-west-004', 'version' => 'latest', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock]);
    $client->getHandlerList()->appendSign(Middleware::tap(function (CommandInterface $command) use (&$commands) {
        $commands[] = $command->getName();
    }), 'record');
    $adapter = new League\Flysystem\AwsS3V3\AwsS3V3Adapter($client, 'bucket');
    Storage::set('b2', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, ['throw' => true], $client));
    config(['offsite-backup.disk' => 'b2']);

    $this->artisan('offsite:mirror', ['--relocate-from' => 'moojing-global.com/media'])
        ->expectsOutput('Moved: 1')
        ->expectsOutput('Failed: 0')
        ->assertExitCode(0);

    expect($commands)->toBe(['ListObjectsV2', 'ListObjectsV2', 'HeadObject', 'CopyObject', 'HeadObject', 'DeleteObject']);
});
