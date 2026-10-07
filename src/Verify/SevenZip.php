<?php

namespace Jothamlec\OffsiteBackup\Verify;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Extracts AES zips with 7-Zip when PHP's libzip can't (common with Nix and Homebrew PHP).
 *
 * The password goes to 7-Zip on stdin, never on its command line, so it doesn't show up in
 * process listings. 7-Zip reads it from the terminal when it has one, so it runs in a new
 * session (setsid, or perl's POSIX::setsid) without a controlling terminal. Only when neither
 * is available does the password fall back to 7-Zip's -p switch.
 */
class SevenZip
{
    /** @var list<string> */
    public const BINARIES = ['7zz', '7z', '7za'];

    public function binary(): ?string
    {
        $finder = new ExecutableFinder;

        foreach (self::BINARIES as $name) {
            $path = $finder->find($name);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    public function extract(string $binary, string $zipPath, string $destination, string $password): void
    {
        $arguments = [$binary, 'x', '-y', '-bd', '-o'.$destination, $zipPath];
        $detach = $this->detachCommand();

        if ($detach === null) {
            array_splice($arguments, 3, 0, ['-p'.$password]);
        }

        $process = new Process([...($detach ?? []), ...$arguments], null, ['LC_ALL' => 'C'], $detach === null ? null : $password."\n", null);
        $process->run();

        if (! $process->isSuccessful()) {
            $output = $process->getOutput()."\n".$process->getErrorOutput();
            $reason = preg_match('/wrong password/i', $output) === 1
                ? 'wrong archive password?'
                : self::lastLines(str_replace($password, '***', $output));

            throw new VerifyFailed("Extraction with {$binary} failed (exit code {$process->getExitCode()}): {$reason}");
        }
    }

    /**
     * A command prefix that runs 7-Zip without a controlling terminal, or null.
     *
     * @return list<string>|null
     */
    protected function detachCommand(): ?array
    {
        $finder = new ExecutableFinder;

        if (($setsid = $finder->find('setsid')) !== null) {
            return [$setsid, '--wait'];
        }

        if (($perl = $finder->find('perl')) !== null) {
            return [$perl, '-MPOSIX', '-e', 'POSIX::setsid(); exec { $ARGV[0] } @ARGV or die "exec $ARGV[0]: $!\n"'];
        }

        return null;
    }

    private static function lastLines(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line): bool => $line !== ''));

        return implode(' | ', array_slice($lines, -3));
    }
}
