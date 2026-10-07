<?php

namespace Jothamlec\OffsiteBackup;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Jothamlec\OffsiteBackup\Commands\DoctorCommand;
use Jothamlec\OffsiteBackup\Commands\HeartbeatTickCommand;
use Jothamlec\OffsiteBackup\Commands\InstallCommand;
use Jothamlec\OffsiteBackup\Commands\VerifyCommand;
use Jothamlec\OffsiteBackup\Manifest\AddOffsiteManifest;
use Jothamlec\OffsiteBackup\Scheduling\ScheduleRegistrar;
use Jothamlec\OffsiteBackup\Support\SchedulerTick;
use Spatie\Backup\Events\BackupManifestWasCreated;

class OffsiteBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Defaults only: a published config/offsite-backup.php wins. spatie's config is never touched here.
        $this->mergeConfigFrom(__DIR__.'/../config/offsite-backup.php', 'offsite-backup');

        $this->app->singleton(SchedulerTick::class, function (): SchedulerTick {
            $path = config('offsite-backup.scheduler_tick_path');

            return new SchedulerTick(is_string($path) && $path !== ''
                ? $path
                : storage_path('framework/offsite-backup/scheduler-tick.json'));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/offsite-backup.php' => config_path('offsite-backup.php'),
            ], 'offsite-backup-config');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                VerifyCommand::class,
                HeartbeatTickCommand::class,
            ]);
        }

        if (config('offsite-backup.manifest', true)) {
            Event::listen(BackupManifestWasCreated::class, AddOffsiteManifest::class);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            (new ScheduleRegistrar((array) config('offsite-backup')))->register($schedule);
        });
    }
}
