<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\IncludePathsAreReadable;
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
