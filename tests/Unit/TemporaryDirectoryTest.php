<?php

use Jothamlec\OffsiteBackup\Tests\TemporaryDirectory;

it('gives each test a registered sandbox', function () {
    expect(TemporaryDirectory::live())->toContain($this->sandboxPath());
});

it('deletes a directory even when parts of it are unreadable', function () {
    $path = TemporaryDirectory::make('offsite-backup-tests-helper-');
    mkdir($path.'/locked/deep', 0777, true);
    file_put_contents($path.'/locked/deep/.env', 'APP_KEY=secret');
    chmod($path.'/locked', 0000);

    expect(TemporaryDirectory::delete($path))->toBeTrue()
        ->and(is_dir($path))->toBeFalse()
        ->and(TemporaryDirectory::live())->not->toContain($path);
});

it('deletes whatever is still registered at exit', function () {
    $script = $this->sandboxPath('exits.php');
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n"
        ."echo Jothamlec\\OffsiteBackup\\Tests\\TemporaryDirectory::make('offsite-backup-tests-exit-'), PHP_EOL;\n"
        ."exit(3);\n");

    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script), $output, $exit);

    expect($exit)->toBe(3)
        ->and($output[0] ?? '')->toContain('offsite-backup-tests-exit-')
        ->and(is_dir($output[0] ?? ''))->toBeFalse();
});

it('sweeps sandboxes a killed run left behind, but not recent ones', function () {
    $stale = sys_get_temp_dir().'/offsite-backup-tests-stale'.bin2hex(random_bytes(3));
    $recent = sys_get_temp_dir().'/offsite-backup-tests-recent'.bin2hex(random_bytes(3));
    mkdir($stale.'/shared', 0777, true);
    file_put_contents($stale.'/shared/.env', 'APP_KEY=leaked');
    mkdir($recent);
    touch($stale, time() - TemporaryDirectory::STALE_AFTER_SECONDS - 60);

    try {
        TemporaryDirectory::sweepStale();

        expect(is_dir($stale))->toBeFalse()
            ->and(is_dir($recent))->toBeTrue()
            ->and(is_dir($this->sandboxPath()))->toBeTrue();
    } finally {
        TemporaryDirectory::delete($stale);
        TemporaryDirectory::delete($recent);
    }
});

it('cleans up when the run is interrupted', function () {
    $script = $this->sandboxPath('interrupted.php');
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).";\n"
        ."echo Jothamlec\\OffsiteBackup\\Tests\\TemporaryDirectory::make('offsite-backup-tests-signal-'), PHP_EOL;\n"
        ."posix_kill(getmypid(), SIGTERM);\nsleep(5);\n");

    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script), $output, $exit);

    expect($exit)->toBe(128 + SIGTERM)
        ->and($output[0] ?? '')->toContain('offsite-backup-tests-signal-')
        ->and(is_dir($output[0] ?? ''))->toBeFalse();
})->skip(fn () => ! function_exists('pcntl_signal') || ! function_exists('posix_kill'), 'needs pcntl and posix');
