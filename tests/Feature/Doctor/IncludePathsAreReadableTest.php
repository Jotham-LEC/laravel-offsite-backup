<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\IncludePathsAreReadable;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Doctor\Status;

it('passes when everything is readable', function () {
    $result = runCheck(IncludePathsAreReadable::class);

    expect($result->status)->toBe(Status::Pass)->and($result->message)->toBe('2 files readable.');
});

it('reports the first unreadable file with its owner and mode', function () {
    $file = $this->sandboxPath('shared/storage/app/public/photo.jpg');
    chmod($file, 0000);

    $result = runCheck(IncludePathsAreReadable::class);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->message)->toContain("Can't read {$file}")
        ->and($result->message)->toMatch('/file \S+:\S+ mode 0000/')
        ->and($result->hint)->toContain('setfacl');
})->skip(fn () => runningAsRoot(), 'root reads everything');

it('reports an unreadable directory, even inside an excluded one', function () {
    // Finder still descends into excluded directories, so this would stop backup:run.
    mkdir($this->sandboxPath('shared/storage/framework/cache'));
    chmod($this->sandboxPath('shared/storage/framework/cache'), 0300);

    $result = runCheck(IncludePathsAreReadable::class);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->message)->toContain('shared/storage/framework/cache')
        ->and($result->message)->toContain('mode 0300');
})->skip(fn () => runningAsRoot(), 'root reads everything');

it('ignores unreadable files in excluded directories', function () {
    file_put_contents($this->sandboxPath('shared/storage/logs/secret.log'), 'x');
    chmod($this->sandboxPath('shared/storage/logs/secret.log'), 0000);

    expect(runCheck(IncludePathsAreReadable::class)->status)->toBe(Status::Pass);
})->skip(fn () => runningAsRoot(), 'root reads everything');

it('warns about include paths that don\'t exist', function () {
    config(['backup.backup.source.files.include' => [$this->sandboxPath('shared'), $this->sandboxPath('gone')]]);

    $result = runCheck(IncludePathsAreReadable::class);

    expect($result->status)->toBe(Status::Warn)->and($result->message)->toContain('gone');
});

it('fails on an unreadable excluded directory, saying that excluding it does not help', function () {
    // storage/logs is excluded; spatie's Finder still opens it (seen in production).
    chmod($this->sandboxPath('shared/storage/logs'), 0000);

    $result = runCheck(IncludePathsAreReadable::class);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->message)->toContain("Can't read {$this->sandboxPath('shared/storage/logs')} (dir ")
        ->and($result->message)->toContain('which is excluded, but spatie walks into excluded directories before filtering')
        ->and($result->hint)->toContain('backup:run will fail here.')
        ->and($result->hint)->toContain('Excluding a directory does not help');
})->skip(fn () => runningAsRoot(), 'root reads everything');

it('descends into excluded directories and reports every unreadable path, not just the first', function () {
    mkdir($this->sandboxPath('shared/storage/framework/cache/data/ab'), 0777, true);
    chmod($this->sandboxPath('shared/storage/framework/cache/data/ab'), 0000);
    file_put_contents($this->sandboxPath('shared/storage/app/secret.txt'), 'x');
    chmod($this->sandboxPath('shared/storage/app/secret.txt'), 0000);

    $result = runCheck(IncludePathsAreReadable::class);
    $walk = (new DoctorContext)->walk();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->message)->toContain('storage/framework/cache/data/ab')
        ->and($result->message)->toContain('storage/app/secret.txt')
        ->and($walk->unreadablePaths)->toHaveCount(2)
        ->and(collect($walk->unreadablePaths)->firstWhere('directory', true)['excluded'])->toBeTrue()
        ->and(collect($walk->unreadablePaths)->firstWhere('directory', false)['excluded'])->toBeFalse()
        // The readable files are still counted.
        ->and($walk->files)->toBe(2);
})->skip(fn () => runningAsRoot(), 'root reads everything');

it('still fails with ignore_unreadable_directories on, because those files would be missing', function () {
    chmod($this->sandboxPath('shared/storage/logs'), 0000);
    config(['backup.backup.source.files.ignore_unreadable_directories' => true]);

    $result = runCheck(IncludePathsAreReadable::class);

    expect($result->status)->toBe(Status::Fail)
        ->and($result->hint)->toContain('skipped silently');
})->skip(fn () => runningAsRoot(), 'root reads everything');
