<?php

namespace Jothamlec\OffsiteBackup\Commands;

use Illuminate\Console\Command;
use Jothamlec\OffsiteBackup\Doctor\Status;
use Jothamlec\OffsiteBackup\Support\Heartbeat;
use Jothamlec\OffsiteBackup\Verify\Verifier;
use Jothamlec\OffsiteBackup\Verify\VerifyReport;

class VerifyCommand extends Command
{
    protected $signature = 'offsite:verify
        {--backup= : A backup\'s path or file name (default: the newest)}
        {--disk= : Override offsite-backup.disk}
        {--name= : Override offsite-backup.name (verify another app\'s backups)}
        {--keep : Keep the extracted files and print where}
        {--json : Print a machine-readable report}
        {--no-ping : Don\'t ping the verify heartbeat}';

    protected $description = 'Downloads an off-site backup, decrypts it and test-restores it (run it off the server)';

    public function handle(Verifier $verifier): int
    {
        /** @var array<string, mixed> $offsite */
        $offsite = (array) config('offsite-backup');

        foreach (['disk', 'name'] as $key) {
            if (is_string($override = $this->option($key)) && $override !== '') {
                $offsite[$key] = $override;
            }
        }

        $password = config('backup.backup.password');
        $backup = $this->option('backup');

        $options = Verifier::optionsFromConfig(
            $offsite,
            is_string($password) && $password !== '' ? $password : null,
            is_string($backup) && $backup !== '' ? $backup : null,
            (bool) $this->option('keep'),
        );

        if (! $this->option('json')) {
            $this->components->info("Verifying {$options->name}/ on disk '{$options->disk}'".($options->backup ? " ({$options->backup})" : ' (newest)').'.');
        }

        $report = $verifier->verify($options);

        if (! $this->option('no-ping')) {
            $this->ping($offsite, $report);
        }

        $this->option('json') ? $this->printJson($report) : $this->printTable($report);

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $offsite
     */
    private function ping(array $offsite, VerifyReport $report): void
    {
        $heartbeat = Heartbeat::fromConfig((array) ($offsite['verify']['heartbeat'] ?? []));

        if ($heartbeat->enabled()) {
            $heartbeat->ping($report->passed(), $report->passed() ? 'verified' : 'offsite:verify failed');
        }
    }

    private function printJson(VerifyReport $report): void
    {
        $this->output->writeln((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function printTable(VerifyReport $report): void
    {
        $this->table(['', 'Step', 'Result'], array_map(fn (array $step): array => [
            match ($step['status']) {
                Status::Pass => '<fg=green>PASS</>',
                Status::Warn => '<fg=yellow>WARN</>',
                Status::Fail => '<fg=red;options=bold>FAIL</>',
            },
            $step['step'],
            $step['detail'],
        ], $report->steps()));

        foreach ($report->databases as $database) {
            if (! empty($database['tables']) && $this->output->isVerbose()) {
                $this->line("  {$database['dump']}:");

                foreach ((array) $database['tables'] as $table => $rows) {
                    $this->line(sprintf('    %-40s %s rows', $table, $rows));
                }
            }
        }

        if ($report->keptAt !== null) {
            $this->components->warn("Extracted files kept in {$report->keptAt}. It holds .env and the data: delete it when done.");
        }

        $report->passed()
            ? $this->components->info('Backup verified.')
            : $this->components->error('Verification failed.');
    }
}
