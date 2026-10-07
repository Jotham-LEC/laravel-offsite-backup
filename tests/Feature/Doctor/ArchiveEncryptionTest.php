<?php

use Jothamlec\OffsiteBackup\Doctor\Checks\ArchiveEncryption;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Jothamlec\OffsiteBackup\Support\ZipCapabilities;
use Jothamlec\OffsiteBackup\Verify\SevenZip;

function fakeZip(bool $aes, ?bool $decrypt = null, ?string $sevenZip = null): void
{
    app()->instance(ZipCapabilities::class, new class($aes, $decrypt ?? $aes) extends ZipCapabilities
    {
        public function __construct(private bool $aes, private bool $decrypt) {}

        public function canEncryptAes256(): bool
        {
            return $this->aes;
        }

        public function canDecryptAes(): bool
        {
            return $this->decrypt;
        }

        public function libzipVersion(): string
        {
            return '1.11.4';
        }
    });

    app()->instance(SevenZip::class, new class($sevenZip) extends SevenZip
    {
        public function __construct(private ?string $path) {}

        public function binary(): ?string
        {
            return $this->path;
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

it('fails when libzip has no AES where backups are scheduled', function () {
    fakeZip(false);
    config(['offsite-backup.schedule.environments' => ['testing']]);

    $result = runCheck(ArchiveEncryption::class);

    expect($result->status)->toBe(Status::Fail)->and($result->message)->toContain("libzip 1.11.4) can't encrypt with AES-256");
});

it('only warns when libzip has no AES on a machine that only verifies', function (bool $decrypt, ?string $sevenZip, string $message) {
    fakeZip(false, $decrypt, $sevenZip);
    config(['offsite-backup.schedule.environments' => ['production']]);

    $result = runCheck(ArchiveEncryption::class);

    expect($result->status)->toBe(Status::Warn)
        ->and($result->message)->toContain($message)
        ->and($result->hint)->toContain('The server that runs backup:run must support AES-256');
})->with([
    'libzip decrypts' => [true, null, 'can decrypt AES but not encrypt it'],
    '7-Zip decrypts' => [false, '/usr/bin/7zz', 'offsite:verify decrypts with /usr/bin/7zz'],
    'nothing decrypts' => [false, null, "offsite:verify can't decrypt AES archives here"],
]);

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
