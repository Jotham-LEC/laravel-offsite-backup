<?php

use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function aesSupported(): bool
{
    return ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256);
}

function runCheck(string $class, bool $writeProbe = false): CheckResult
{
    return app($class)->run(new DoctorContext(writeProbe: $writeProbe));
}

function runningAsRoot(): bool
{
    return function_exists('posix_geteuid') && posix_geteuid() === 0;
}
