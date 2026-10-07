<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\PathLayout;
use Jothamlec\OffsiteBackup\Doctor\Status;

it('passes for the hardened layout', function () {
    expect(runCheck(PathLayout::class)->status)->toBe(Status::Pass);
});

it('fails when an include outside base_path has no relative_path', function () {
    config(['backup.backup.source.files.relative_path' => null]);

    $result = runCheck(PathLayout::class);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain('the zip stores absolute paths');
});

it('fails on a symlinked include path without follow_links', function () {
    symlink($this->sandboxPath('shared'), $this->sandboxPath('current-shared'));
    config(['backup.backup.source.files.include' => [$this->sandboxPath('current-shared')]]);

    $result = runCheck(PathLayout::class);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain('is a symlink and follow_links is false');
});

it('warns about a symlinked directory inside an include path', function () {
    // A Deployer release: storage/ is a symlink into shared/.
    mkdir($this->sandboxPath('release'));
    symlink($this->sandboxPath('shared/storage'), $this->sandboxPath('release/storage'));
    config([
        'backup.backup.source.files.include' => [$this->sandboxPath('release')],
        'backup.backup.source.files.relative_path' => $this->sandboxPath('release'),
    ]);

    $result = runCheck(PathLayout::class);

    expect($result->status)->toBe(Status::Warn)->and($result->message)->toContain('release/storage is a symlinked directory and is skipped');
});

it('warns when the temporary directory is inside an include path and not excluded', function () {
    config([
        'backup.backup.temporary_directory' => $this->sandboxPath('shared/storage/app/backup-temp'),
        'backup.backup.source.files.exclude' => [],
    ]);

    expect(runCheck(PathLayout::class)->status)->toBe(Status::Warn);

    config(['backup.backup.source.files.exclude' => [$this->sandboxPath('shared/storage/app/backup-temp')]]);

    expect(runCheck(PathLayout::class)->status)->toBe(Status::Pass);
});
