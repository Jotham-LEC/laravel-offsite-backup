<?php

namespace Jothamlec\OffsiteBackup\Support;

final class SharedPath
{
    /**
     * The configured path, else Deployer's {{deploy_path}}/shared when $basePath is a
     * releases/N directory (resolved through the `current` symlink), else $basePath.
     */
    public static function resolve(?string $configured, string $basePath): string
    {
        if ($configured !== null && $configured !== '') {
            return rtrim($configured, '/') ?: '/';
        }

        $real = realpath($basePath) ?: $basePath;
        $real = rtrim($real, '/');

        if (basename(dirname($real)) === 'releases') {
            $shared = dirname($real, 2).'/shared';

            if (is_dir($shared)) {
                return realpath($shared) ?: $shared;
            }
        }

        return rtrim($basePath, '/');
    }
}
