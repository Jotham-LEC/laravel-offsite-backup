<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\ArchiveEncryption;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Jothamlec\OffsiteBackup\Support\ZipCapabilities;

function fakeZip(bool $aes): void
{
    app()->instance(ZipCapabilities::class, new class($aes) extends ZipCapabilities
    {
        public function __construct(private bool $aes) {}

        public function canEncryptAes256(): bool
        {
            return $this->aes;
        }

        public function canDecryptAes(): bool
        {
            return $this->aes;
        }

        public function libzipVersion(): string
        {
            return '1.11.4';
        }
    });
}

beforeEach(function () {
    fakeZip(true);
    config(['backup.backup.password' => 'secret', 'backup.backup.encryption' => 'aes256']);
});

it('passes with AES-256, aes256 and a password', function () {
    expect(runCheck(ArchiveEncryption::class)->status)->toBe(Status::Pass);
});

it('fails when libzip has no AES', function () {
    fakeZip(false);

    $result = runCheck(ArchiveEncryption::class);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain("libzip 1.11.4) can't encrypt with AES-256");
});

it('judges the encryption setting', function (mixed $encryption, Status $status) {
    config(['backup.backup.encryption' => $encryption]);

    expect(runCheck(ArchiveEncryption::class)->status)->toBe($status);
})->with([
    ['none', Status::Fail],
    [null, Status::Fail],
    ['default', Status::Warn],
    ['aes128', Status::Pass],
    ['rot13', Status::Fail],
]);

it('fails without a password', function () {
    config(['backup.backup.password' => null]);

    $result = runCheck(ArchiveEncryption::class);

    expect($result->status)->toBe(Status::Fail)->and($result->hint)->toContain('BACKUP_ARCHIVE_PASSWORD');
});
