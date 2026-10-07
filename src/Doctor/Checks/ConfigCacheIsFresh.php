<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;

class ConfigCacheIsFresh implements Check
{
    public function name(): string
    {
        return 'Config cache';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $cachePath = $this->cachedConfigPath();

        if (! is_file($cachePath)) {
            return CheckResult::pass('Config is not cached; .env is read on every run.');
        }

        $cached = filemtime($cachePath);
        $env = is_file($this->environmentFilePath()) ? filemtime($this->environmentFilePath()) : false;

        if ($cached !== false && $env !== false && $env > $cached) {
            return CheckResult::warn(
                '.env changed after the config was cached: the scheduler still uses the old values.',
                'php artisan config:cache (dep offsite:env does it for you).',
            );
        }

        return CheckResult::pass('The config cache is newer than .env.');
    }

    protected function cachedConfigPath(): string
    {
        return app()->getCachedConfigPath();
    }

    protected function environmentFilePath(): string
    {
        return app()->environmentFilePath();
    }
}
