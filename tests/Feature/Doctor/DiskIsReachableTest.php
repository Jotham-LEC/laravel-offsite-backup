<?php

use Illuminate\Support\Facades\Storage;
use Jothamlec\OffsiteBackup\Doctor\Checks\DiskIsReachable;
use Jothamlec\OffsiteBackup\Doctor\Status;

it('lists the backup folder read-only', function () {
    $result = runCheck(DiskIsReachable::class);

    expect($result->status)->toBe(Status::Pass)
        ->and($result->message)->toContain("Disk 'backups': listed offsite-test/")
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

it('counts only the top-level archives and warns about other objects under the backup folder', function () {
    $disk = Storage::disk('backups');
    $disk->put('offsite-test/2026-10-01-03-00-00.zip', 'zip');
    $disk->put('offsite-test/2026-10-02-03-00-00.zip', 'zip');

    $clean = runCheck(DiskIsReachable::class);

    expect($clean->status)->toBe(Status::Pass)
        ->and($clean->message)->toContain('listed offsite-test/ (2 backups)')
        ->and($clean->message)->toContain('only backup archives under offsite-test/');

    $disk->put('offsite-test/media/media/a.png', 'a');
    $disk->put('offsite-test/media/og/b.png', 'b');

    $mirrored = runCheck(DiskIsReachable::class);

    expect($mirrored->status)->toBe(Status::Warn)
        ->and($mirrored->message)->toContain('listed offsite-test/ (2 backups)')
        ->and($mirrored->message)->toContain('2 non-zip object(s) under offsite-test/')
        ->and($mirrored->message)->toContain('HEAD request')
        ->and($mirrored->hint)->toContain('php artisan offsite:mirror --relocate-from=offsite-test/media (to offsite-test-media/');
});

it('stops the stray-object listing at the cap', function () {
    $disk = Storage::disk('backups');

    for ($i = 0; $i <= DiskIsReachable::LISTING_CAP; $i++) {
        $disk->put("offsite-test/other/{$i}.txt", '');
    }

    $result = runCheck(DiskIsReachable::class);

    expect($result->message)->toContain('at least '.DiskIsReachable::LISTING_CAP.' non-zip')
        ->and($result->hint)->toContain('sibling prefix such as offsite-test-media/');
});

it('writes and deletes a probe with --write-probe', function () {
    $result = runCheck(DiskIsReachable::class, writeProbe: true);

    expect($result->status)->toBe(Status::Pass)
        ->and($result->message)->toContain('wrote and deleted offsite-test/.offsite-doctor-probe-')
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

it('fails for a disk missing from filesystems.php', function () {
    config(['backup.backup.destination.disks' => ['b2']]);

    $result = runCheck(DiskIsReachable::class);

    expect($result->status)->toBe(Status::Fail)->and($result->hint)->toContain('offsite:install --disk=b2');
});

it('warns when the disk swallows errors', function () {
    config(['filesystems.disks.backups.throw' => false]);

    expect(runCheck(DiskIsReachable::class)->status)->toBe(Status::Warn);
});

it('requires when_required checksums on non-AWS endpoints', function () {
    $b2 = ['driver' => 's3', 'endpoint' => 'https://s3.us-west-004.backblazeb2.com', 'request_checksum_calculation' => 'when_required'];

    $results = DiskIsReachable::s3Settings('b2', $b2);

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(Status::Fail)
        ->and($results[0]->message)->toContain("response_checksum_validation isn't 'when_required'")
        ->and(DiskIsReachable::s3Settings('b2', [...$b2, 'response_checksum_validation' => 'when_required']))->toBe([])
        ->and(DiskIsReachable::s3Settings('s3', ['driver' => 's3', 'endpoint' => 'https://s3.eu-central-1.amazonaws.com']))->toBe([])
        ->and(DiskIsReachable::s3Settings('s3', ['driver' => 's3']))->toBe([]);
});

it('fails when the bucket can\'t be listed', function () {
    config(['filesystems.disks.broken' => [
        'driver' => 's3',
        'key' => 'k',
        'secret' => 's',
        'region' => 'us-east-1',
        'bucket' => 'b',
        'endpoint' => 'http://127.0.0.1:9',
        'use_path_style_endpoint' => true,
        'request_checksum_calculation' => 'when_required',
        'response_checksum_validation' => 'when_required',
        'throw' => true,
        'http' => ['connect_timeout' => 1],
        'retries' => 0,
    ], 'backup.backup.destination.disks' => ['broken']]);

    $result = runCheck(DiskIsReachable::class);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain("Disk 'broken': can't list offsite-test/");
});
