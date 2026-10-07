<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Support\ZipCapabilities;
use Jothamlec\OffsiteBackup\Verify\SevenZip;

/**
 * The server that runs backup:run must encrypt with AES-256. A machine whose APP_ENV isn't in
 * schedule.environments (a laptop or CI box that only runs offsite:verify) only warns, as long
 * as it can decrypt AES with libzip or 7-Zip.
 */
class ArchiveEncryption implements Check
{
    public function __construct(
        private readonly ZipCapabilities $zip,
        private readonly SevenZip $sevenZip = new SevenZip,
    ) {}

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
            $results[] = $this->backsUpHere()
                ? CheckResult::fail(
                    "PHP's zip extension (libzip {$this->zip->libzipVersion()}) can't encrypt with AES-256.",
                    'Use a php-zip built against libzip with a crypto backend (Debian/Ubuntu and the ondrej PPA packages are); check with php -r \'var_dump(ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256));\'',
                )
                : $this->verifyOnlyResult();
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

    /**
     * Whether backups are scheduled in this environment (schedule.environments; [] is everywhere).
     */
    private function backsUpHere(): bool
    {
        $environments = array_values(array_filter((array) config('offsite-backup.schedule.environments', []), 'is_string'));

        return $environments === [] || app()->environment($environments);
    }

    private function verifyOnlyResult(): CheckResult
    {
        $where = sprintf("APP_ENV '%s' isn't in schedule.environments, so this machine only verifies", app()->environment());
        $serverHint = 'The server that runs backup:run must support AES-256 (run offsite:doctor there).';

        if ($this->zip->canDecryptAes()) {
            return CheckResult::warn("This PHP's libzip ({$this->zip->libzipVersion()}) can decrypt AES but not encrypt it; {$where}.", $serverHint);
        }

        $sevenZip = $this->sevenZip->binary();

        if ($sevenZip !== null) {
            return CheckResult::warn("This PHP's libzip ({$this->zip->libzipVersion()}) has no AES; {$where}, and offsite:verify decrypts with {$sevenZip}.", $serverHint);
        }

        return CheckResult::warn(
            "This PHP's libzip ({$this->zip->libzipVersion()}) has no AES and no 7-Zip is on PATH: offsite:verify can't decrypt AES archives here.",
            'Install 7-Zip (7zz, 7z or 7za on PATH) for offsite:verify. '.$serverHint,
        );
    }
}
