<?php

namespace Jothamlec\OffsiteBackup\Verify;

use Jothamlec\OffsiteBackup\Support\ZipCapabilities;
use ZipArchive;

class ArchiveExtractor
{
    private const AES_METHODS = [ZipArchive::EM_AES_128, ZipArchive::EM_AES_192, ZipArchive::EM_AES_256];

    public function __construct(private readonly ZipCapabilities $capabilities) {}

    /**
     * Extracts the archive into $destination and returns its entry names.
     *
     * @return array{entries: list<string>, encryption: string}
     */
    public function extract(string $zipPath, string $destination, ?string $password): array
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new VerifyFailed("Can't open the archive (ZipArchive error {$opened}): it is corrupt or not a zip.");
        }

        try {
            $entries = [];
            $methods = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    continue;
                }

                $entries[] = $stat['name'];
                $methods[] = $stat['encryption_method'];
            }

            if ($entries === []) {
                throw new VerifyFailed('The archive has no entries.');
            }

            $encrypted = array_filter($methods, fn (int $method): bool => $method !== ZipArchive::EM_NONE);
            $aes = array_filter($encrypted, fn (int $method): bool => in_array($method, self::AES_METHODS, true));
            $encryption = $encrypted === [] ? 'none' : ($aes !== [] ? 'aes' : 'zipcrypto');

            if ($aes !== [] && ! $this->capabilities->canDecryptAes()) {
                throw new VerifyFailed(
                    "The archive is AES-encrypted, but this PHP's libzip ({$this->capabilities->libzipVersion()}) can't decrypt AES. "
                    .'Run offsite:verify where ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, false) is true.'
                );
            }

            if ($encrypted !== [] && ($password === null || $password === '')) {
                throw new VerifyFailed('The archive is encrypted but no password is set (BACKUP_ARCHIVE_PASSWORD).');
            }

            if ($password !== null && $password !== '') {
                $zip->setPassword($password);
            }

            if (! is_dir($destination)) {
                mkdir($destination, 0700, true);
            }

            if (! @$zip->extractTo($destination)) {
                throw new VerifyFailed(
                    $encrypted !== []
                        ? 'Extraction failed: wrong archive password? ('.$zip->getStatusString().')'
                        : 'Extraction failed: '.$zip->getStatusString()
                );
            }
        } finally {
            $zip->close();
        }

        $extracted = self::countFiles($destination);

        if ($extracted === 0) {
            throw new VerifyFailed('Extraction produced no files: wrong archive password?');
        }

        return ['entries' => $entries, 'encryption' => $encryption];
    }

    public static function countFiles(string $directory): int
    {
        if (! is_dir($directory)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $count++;
            }
        }

        return $count;
    }
}
