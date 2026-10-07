<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\ZipCapabilities;

class ArchiveEncryption implements Check
{
    public function __construct(private readonly ZipCapabilities $zip) {}

    public function name(): string
    {
        return 'Archive encryption';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $results = [];
        $encryption = config('backup.backup.encryption');
        $password = config('backup.backup.password');

        if (! $this->zip->canEncryptAes256()) {
            $results[] = CheckResult::fail(
                "PHP's zip extension (libzip {$this->zip->libzipVersion()}) can't encrypt with AES-256.",
                'Use a php-zip built against libzip with a crypto backend (Debian/Ubuntu and the ondrej PPA packages are); check with php -r \'var_dump(ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256));\'',
            );
        }

        if ($encryption === null || $encryption === false || $encryption === 'none') {
            $results[] = CheckResult::fail('backup.encryption is off: archives are uploaded unencrypted, .env included.', "Set backup.encryption to 'aes256'.");
        } elseif ($encryption === 'default') {
            $results[] = CheckResult::warn("backup.encryption is 'default'.", "Set 'aes256' explicitly.");
        } elseif (! in_array($encryption, ['aes128', 'aes192', 'aes256'], true)) {
            $results[] = CheckResult::fail('backup.encryption is '.var_export($encryption, true).'.', "Set 'aes256'.");
        }

        if (! is_string($password) || $password === '') {
            $results[] = CheckResult::fail('backup.password is empty: spatie skips encryption without one.', 'Set BACKUP_ARCHIVE_PASSWORD (one password per app, kept outside the server too).');
        }

        return CheckResult::combine($results, "AES-256 available (libzip {$this->zip->libzipVersion()}), encryption '{$encryption}', password set.");
    }
}
