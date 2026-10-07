<?php

namespace Jothamlec\OffsiteBackup\Commands;

use Illuminate\Console\Command;
use Jothamlec\OffsiteBackup\Doctor\Check;
use Jothamlec\OffsiteBackup\Doctor\CheckResult;
use Jothamlec\OffsiteBackup\Doctor\DoctorContext;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'offsite:doctor
        {--write-probe : Also write and delete a tiny object on the disk (Object Lock keeps it)}
        {--json : Print the results as JSON}';

    protected $description = 'Pre-flight checks for off-site backups; exits non-zero when one fails';

    public function handle(): int
    {
        if ($this->option('write-probe') && ! $this->option('json')) {
            $this->components->warn('--write-probe writes a small object under the backup name. With Object Lock it stays until its retention ends.');
        }

        $context = new DoctorContext(writeProbe: (bool) $this->option('write-probe'));
        $rows = [];

        foreach ((array) config('offsite-backup.doctor.checks', []) as $class) {
            [$name, $result] = $this->runCheck($class, $context);
            $rows[] = ['check' => $name, 'status' => $result->status, 'message' => $result->message, 'hint' => $result->hint];
        }

        $worst = Status::worst(...array_column($rows, 'status'));

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode([
                'status' => $worst->value,
                'checks' => array_map(fn (array $row): array => [...$row, 'status' => $row['status']->value], $rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['', 'Check', 'Result', 'Fix'], array_map(fn (array $row): array => [
                $this->badge($row['status']),
                $row['check'],
                $row['message'],
                $row['hint'] ?? '',
            ], $rows));

            $counts = array_count_values(array_map(fn (array $row): string => $row['status']->value, $rows));
            $this->line(sprintf('%d passed, %d warnings, %d failed.', $counts['PASS'] ?? 0, $counts['WARN'] ?? 0, $counts['FAIL'] ?? 0));
        }

        return $worst === Status::Fail ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: CheckResult}
     */
    private function runCheck(mixed $class, DoctorContext $context): array
    {
        if (! is_string($class) || ! is_a($class, Check::class, true)) {
            return [is_string($class) ? $class : get_debug_type($class), CheckResult::fail('Not an '.Check::class.'.')];
        }

        try {
            $check = $this->laravel->make($class);

            return [$check->name(), $check->run($context)];
        } catch (Throwable $exception) {
            return [class_basename($class), CheckResult::fail('The check threw: '.$exception->getMessage())];
        }
    }

    private function badge(Status $status): string
    {
        return match ($status) {
            Status::Pass => '<fg=green>PASS</>',
            Status::Warn => '<fg=yellow>WARN</>',
            Status::Fail => '<fg=red;options=bold>FAIL</>',
        };
    }
}
