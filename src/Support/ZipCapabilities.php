<?php

namespace Jothamlec\OffsiteBackup\Support;

use ZipArchive;

/**
 * Whether PHP's libzip can do AES. Many distro builds can't (built without a crypto backend).
 */
class ZipCapabilities
{
    public function canEncryptAes256(): bool
    {
        return class_exists(ZipArchive::class) && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true);
    }

    public function canDecryptAes(): bool
    {
        return class_exists(ZipArchive::class) && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, false);
    }

    public function libzipVersion(): string
    {
        return defined(ZipArchive::class.'::LIBZIP_VERSION') ? (string) ZipArchive::LIBZIP_VERSION : 'unknown';
    }
}
