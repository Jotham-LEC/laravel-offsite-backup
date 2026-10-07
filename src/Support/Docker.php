<?php

namespace Jothamlec\OffsiteBackup\Support;

use Illuminate\Support\Facades\Process;

class Docker
{
    private ?bool $available = null;

    /**
     * Whether the docker CLI is installed and its daemon answers.
     */
    public function available(): bool
    {
        return $this->available ??= (new BinaryLocator)->find('docker') !== null
            && Process::timeout(20)->run(['docker', 'info', '--format', '{{.ServerVersion}}'])->successful();
    }
}
