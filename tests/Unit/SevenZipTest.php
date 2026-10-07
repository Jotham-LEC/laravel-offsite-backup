<?php

use Jothamlec\OffsiteBackup\Verify\SevenZip;
use Jothamlec\OffsiteBackup\Verify\VerifyFailed;

/**
 * A fake 7-Zip that records its arguments and stdin, then writes one file into -o<dir>.
 */
function fakeSevenZip(string $directory, int $exit = 0): string
{
    $script = $directory.'/7z';
    file_put_contents($script, "#!/bin/sh\n"
        .'printf "%s\n" "$@" > '.escapeshellarg($directory.'/args')."\n"
        .'cat > '.escapeshellarg($directory.'/stdin')."\n"
        .'for a in "$@"; do case "$a" in -o*) echo restored > "${a#-o}/file.txt";; esac; done'."\n"
        ."exit {$exit}\n");
    chmod($script, 0755);

    return $script;
}

it('passes the password on stdin, not on the command line', function () {
    $dir = $this->sandboxPath('7z');
    mkdir($dir.'/out', 0777, true);

    (new SevenZip)->extract(fakeSevenZip($dir), $dir.'/backup.zip', $dir.'/out', 'pa$$ word');

    expect(file_get_contents($dir.'/stdin'))->toBe("pa\$\$ word\n")
        ->and(file_get_contents($dir.'/args'))->not->toContain('pa$$ word')
        ->and(file_get_contents($dir.'/args'))->toContain("-o{$dir}/out\n{$dir}/backup.zip")
        ->and(file_get_contents($dir.'/out/file.txt'))->toBe("restored\n");
})->skip(fn () => trim((string) shell_exec('command -v setsid || command -v perl')) === '', 'needs setsid or perl');

it('reports a failed extraction without the password', function () {
    $dir = $this->sandboxPath('7z');
    mkdir($dir.'/out', 0777, true);

    expect(fn () => (new SevenZip)->extract(fakeSevenZip($dir, 2), $dir.'/backup.zip', $dir.'/out', 'hunter2'))
        ->toThrow(VerifyFailed::class, 'failed (exit code 2)');
});
