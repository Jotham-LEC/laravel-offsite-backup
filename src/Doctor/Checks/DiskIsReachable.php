<?php

namespace Jothamlec\OffsiteBackup\Doctor\Checks;

use Illuminate\Support\Facades\Storage;
use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Throwable;

class DiskIsReachable implements Check
{
    public function name(): string
    {
        return 'Disk reachable';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $disks = array_values(array_filter((array) config('backup.backup.destination.disks', []), 'is_string'));
        $name = (string) config('backup.backup.name');

        if ($disks === []) {
            return CheckResult::fail('backup.destination.disks is empty.', 'Set OFFSITE_BACKUP_DISK and its disk in config/filesystems.php.');
        }

        $results = [];

        foreach ($disks as $disk) {
            array_push($results, ...$this->checkDisk($disk, $name, $context->writeProbe));
        }

        return CheckResult::combine($results);
    }

    /**
     * @return list<CheckResult>
     */
    private function checkDisk(string $disk, string $name, bool $writeProbe): array
    {
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config)) {
            return [CheckResult::fail("Disk '{$disk}' isn't in config/filesystems.php.", 'Add it: php artisan offsite:install --disk=b2 (or r2, s3, wasabi) prints one.')];
        }

        $results = [];

        if (($config['driver'] ?? null) === 's3') {
            $results = [...$results, ...self::s3Settings($disk, $config)];
        }

        if (($config['throw'] ?? false) !== true) {
            $results[] = CheckResult::warn("Disk '{$disk}' has throw => false: failed writes can pass silently.", "Set 'throw' => true.");
        }

        try {
            $count = count(Storage::disk($disk)->files($name));
            $results[] = CheckResult::pass("Disk '{$disk}': listed {$name}/ ({$count} files).");
        } catch (Throwable $exception) {
            return [...$results, CheckResult::fail(
                "Disk '{$disk}': can't list {$name}/: ".self::short($exception),
                'Check the key, secret, bucket, region and endpoint, and that the key may list the "'.$name.'/" prefix.',
            )];
        }

        if ($writeProbe) {
            $results[] = $this->probe($disk, $name);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<CheckResult>
     */
    public static function s3Settings(string $disk, array $config): array
    {
        $endpoint = is_string($config['endpoint'] ?? null) ? $config['endpoint'] : '';
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        if ($endpoint === '' || str_ends_with($host, '.amazonaws.com')) {
            return [];
        }

        $wrong = array_values(array_filter(
            ['request_checksum_calculation', 'response_checksum_validation'],
            fn (string $key): bool => ($config[$key] ?? null) !== 'when_required',
        ));

        if ($wrong === []) {
            return [];
        }

        return [CheckResult::fail(
            "Disk '{$disk}' ({$host}) isn't AWS but ".implode(' and ', $wrong)." isn't 'when_required': uploads fail with checksum errors.",
            "Set 'request_checksum_calculation' => 'when_required' and 'response_checksum_validation' => 'when_required'.",
        )];
    }

    private function probe(string $disk, string $name): CheckResult
    {
        $path = $name.'/.offsite-doctor-probe-'.date('Ymd-His').'.txt';

        try {
            Storage::disk($disk)->put($path, 'offsite:doctor write probe');
        } catch (Throwable $exception) {
            return CheckResult::fail("Disk '{$disk}': can't write {$path}: ".self::short($exception), 'The key needs write access to the "'.$name.'/" prefix.');
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            return CheckResult::warn(
                "Disk '{$disk}': wrote {$path} but couldn't delete it.",
                'Expected with Object Lock or a key without delete rights; the object stays until its retention ends.',
            );
        }

        return CheckResult::pass("Disk '{$disk}': wrote and deleted {$path}.");
    }

    private static function short(Throwable $exception): string
    {
        $message = strtok($exception->getMessage(), "\n");

        return mb_strimwidth($message === false ? $exception::class : $message, 0, 240, '...');
    }
}
