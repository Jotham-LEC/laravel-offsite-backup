<?php

namespace Jothamlec\OffsiteBackup\Install;

use InvalidArgumentException;

/**
 * The config/filesystems.php disk snippets printed by `offsite:install --disk=...`.
 */
final class DiskPreset
{
    /**
     * @var array<string, array{disk: string, prefix: string, region: string, endpoint: ?string, path_style: bool, aws: bool, note: string}>
     */
    private const PRESETS = [
        'b2' => [
            'disk' => 'b2',
            'prefix' => 'B2',
            'region' => 'us-west-004',
            'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
            'path_style' => false,
            'aws' => false,
            'note' => 'Backblaze B2 (S3-compatible API). Create an application key restricted to the bucket and to the name prefix "<name>/".',
        ],
        'r2' => [
            'disk' => 'r2',
            'prefix' => 'R2',
            'region' => 'auto',
            'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com',
            'path_style' => true,
            'aws' => false,
            'note' => 'Cloudflare R2. Create an API token with Object Read & Write, scoped to the bucket.',
        ],
        's3' => [
            'disk' => 's3-offsite',
            'prefix' => 'OFFSITE_S3',
            'region' => 'eu-central-1',
            'endpoint' => null,
            'path_style' => false,
            'aws' => true,
            'note' => 'Amazon S3. A separate disk from Laravel\'s "s3", with an IAM policy limited to arn:aws:s3:::<bucket>/<name>/*.',
        ],
        'wasabi' => [
            'disk' => 'wasabi',
            'prefix' => 'WASABI',
            'region' => 'eu-central-1',
            'endpoint' => 'https://s3.eu-central-1.wasabisys.com',
            'path_style' => false,
            'aws' => false,
            'note' => 'Wasabi. Create a sub-user with a policy limited to the bucket and the "<name>/" prefix.',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function diskName(string $preset): string
    {
        return self::preset($preset)['disk'];
    }

    public static function snippet(string $preset): string
    {
        $p = self::preset($preset);
        $env = fn (string $key, ?string $default = null): string => $default === null
            ? "env('{$p['prefix']}_{$key}')"
            : "env('{$p['prefix']}_{$key}', '{$default}')";

        $lines = [
            "        // {$p['note']}",
            "        '{$p['disk']}' => [",
            "            'driver' => 's3',",
            "            'key' => {$env('ACCESS_KEY_ID')},",
            "            'secret' => {$env('SECRET_ACCESS_KEY')},",
            "            'region' => {$env('REGION', $p['region'])},",
            "            'bucket' => {$env('BUCKET')},",
        ];

        if ($p['endpoint'] !== null) {
            $lines[] = "            'endpoint' => {$env('ENDPOINT', $p['endpoint'])},";
        }

        $lines[] = "            'use_path_style_endpoint' => ".($p['path_style'] ? 'true' : 'false').',';

        if (! $p['aws']) {
            $lines[] = '            // The AWS SDK\'s default CRC checksums break uploads to non-AWS endpoints.';
            $lines[] = "            'request_checksum_calculation' => 'when_required',";
            $lines[] = "            'response_checksum_validation' => 'when_required',";
        }

        $lines[] = "            'throw' => true,";
        $lines[] = '        ],';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    public static function envKeys(string $preset): array
    {
        $p = self::preset($preset);
        $keys = ['ACCESS_KEY_ID', 'SECRET_ACCESS_KEY', 'REGION', 'BUCKET'];

        if ($p['endpoint'] !== null) {
            $keys[] = 'ENDPOINT';
        }

        return array_map(fn (string $key): string => "{$p['prefix']}_{$key}", $keys);
    }

    /**
     * @return array{disk: string, prefix: string, region: string, endpoint: ?string, path_style: bool, aws: bool, note: string}
     */
    private static function preset(string $preset): array
    {
        return self::PRESETS[$preset] ?? throw new InvalidArgumentException(
            "Unknown disk preset '{$preset}'. Use one of: ".implode(', ', self::names()).'.'
        );
    }
}
