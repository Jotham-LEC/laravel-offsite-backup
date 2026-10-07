<?php

use Jothamlec\OffsiteBackup\Install\DiskPreset;

it('sets when_required checksums and throw for non-AWS endpoints', function (string $preset, string $disk) {
    $snippet = DiskPreset::snippet($preset);
    $config = eval("return [\n{$snippet}];");

    expect($config[$disk]['request_checksum_calculation'])->toBe('when_required')
        ->and($config[$disk]['response_checksum_validation'])->toBe('when_required')
        ->and($config[$disk]['throw'])->toBeTrue()
        ->and($config[$disk]['driver'])->toBe('s3')
        ->and($config[$disk]['endpoint'])->not->toBeNull();
})->with([
    ['b2', 'b2'],
    ['r2', 'r2'],
    ['wasabi', 'wasabi'],
]);

it('leaves AWS checksums at the SDK default and uses its own disk name', function () {
    $snippet = DiskPreset::snippet('s3');

    expect($snippet)->not->toContain('when_required')
        ->and($snippet)->not->toContain("'endpoint'")
        ->and($snippet)->toContain("'s3-offsite' => [")
        ->and($snippet)->toContain("'throw' => true");
});

it('lists the env keys to set', function () {
    expect(DiskPreset::envKeys('b2'))->toBe(['B2_ACCESS_KEY_ID', 'B2_SECRET_ACCESS_KEY', 'B2_REGION', 'B2_BUCKET', 'B2_ENDPOINT'])
        ->and(DiskPreset::envKeys('s3'))->not->toContain('OFFSITE_S3_ENDPOINT');
});

it('rejects unknown presets', function () {
    DiskPreset::snippet('dropbox');
})->throws(InvalidArgumentException::class);
