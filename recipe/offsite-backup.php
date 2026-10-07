<?php

namespace Deployer;

/*
 * jothamlec/laravel-offsite-backup: Deployer recipe.
 *
 *   require 'recipe/laravel.php';
 *   require 'vendor/jothamlec/laravel-offsite-backup/recipe/offsite-backup.php';
 *
 *   set('offsite_secrets', [
 *       'BACKUP_ARCHIVE_PASSWORD' => 'op://Ops/my-app-backup/archivePassword',
 *       'B2_ACCESS_KEY_ID' => 'op://Ops/my-app-backup/keyID',
 *       'B2_SECRET_ACCESS_KEY' => 'op://Ops/my-app-backup/applicationKey',
 *       'B2_BUCKET' => 'my-backups',
 *       'B2_REGION' => 'us-west-004',
 *       'B2_ENDPOINT' => 'https://s3.us-west-004.backblazeb2.com',
 *       'OFFSITE_BACKUP_CONNECTIONS' => 'pgsql',
 *       'OFFSITE_BACKUP_HEARTBEAT_URL' => 'op://Ops/my-app-backup/heartbeat?',
 *   ]);
 *
 *   dep offsite:env production        write the settings into shared/.env, then config:cache
 *   dep offsite:acl production        give offsite_reader_user read ACLs (optional)
 *   dep offsite:scheduler production  install the scheduler's cron line (refuses duplicates)
 *   dep offsite:doctor production     pre-flight checks on the server
 *   dep offsite:run production        take a backup now
 *   dep offsite:list production       list the backups on the disk
 *   dep offsite:verify production     download, decrypt and test-restore here, not on the server
 */

use Jothamlec\OffsiteBackup\Deployer\EnvBlock;
use Jothamlec\OffsiteBackup\Deployer\SchedulerDetector;
use Jothamlec\OffsiteBackup\Deployer\SecretResolver;
use Jothamlec\OffsiteBackup\Deployer\Stage;
use Symfony\Component\Console\Input\InputOption;

foreach (['EnvBlock', 'SecretResolver', 'SchedulerDetector', 'Stage'] as $offsiteHelper) {
    if (! class_exists("Jothamlec\\OffsiteBackup\\Deployer\\{$offsiteHelper}")) {
        require_once __DIR__."/../src/Deployer/{$offsiteHelper}.php";
    }
}

// Hosts whose `stage` label (or alias) may run these tasks. [] allows all.
set('offsite_stages', ['production']);

// The folder in the bucket (OFFSITE_BACKUP_NAME).
set('offsite_name', fn (): string => Stage::slug((string) get('application', 'laravel')));

// ENV key => source; see SecretResolver: 'env:VAR', 'op://...', 'doppler:NAME', 'file:/path', literal.
set('offsite_secrets', []);

// offsite:acl: the user that runs the scheduler when it isn't the deploy user (e.g. www-data).
set('offsite_reader_user', null);

// offsite:acl: paths under shared/ that user needs to read, besides .env.
set('offsite_acl_paths', ['storage']);

// offsite:scheduler: e.g. '002' so files the scheduler creates stay group-writable for PHP-FPM.
set('offsite_cron_umask', null);
set('offsite_cron_marker', '# {{offsite_name}} scheduler (managed by dep offsite:scheduler)');

// offsite:verify runs this in the local project.
set('offsite_verify_command', 'php artisan offsite:verify');

if (! Deployer::get()->inputDefinition->hasOption('force')) {
    option('force', null, InputOption::VALUE_NONE, 'offsite:scheduler: install even if another scheduler entry exists');
}

function offsiteGuard(): void
{
    $allowed = array_values(array_filter((array) get('offsite_stages', []), 'is_string'));
    $host = currentHost();

    if (! Stage::allowed((array) $host->get('labels', []), $host->getAlias() ?? '', $allowed)) {
        throw error('Off-site backup tasks are limited to offsite_stages ['.implode(', ', $allowed)."]; refusing to run on {$host->getAlias()}.");
    }
}

/**
 * @return array<string, string>
 */
function offsiteResolveSecrets(): array
{
    $resolver = new SecretResolver(fn (string $command): string => runLocally($command));

    return $resolver->resolveAll((array) get('offsite_secrets', []));
}

function offsiteArtisan(string $command, int $timeout = 300): void
{
    offsiteGuard();
    writeln(run("cd {{current_path}} && {{bin/php}} artisan {$command}", timeout: $timeout));
}

desc('Writes the off-site backup settings into shared/.env (in place) and caches the config');
task('offsite:env', function (): void {
    offsiteGuard();

    $values = [
        'OFFSITE_BACKUP_NAME' => (string) get('offsite_name'),
        'OFFSITE_BACKUP_SHARED_PATH' => run('cd {{deploy_path}}/shared && pwd -P'),
        ...offsiteResolveSecrets(),
    ];

    $block = EnvBlock::render($values);
    $local = tempnam(sys_get_temp_dir(), 'offsite-env');
    chmod($local, 0600);
    file_put_contents($local, $block);

    try {
        upload($local, '{{deploy_path}}/shared/.env.offsite-block');
    } finally {
        unlink($local);
    }

    run(EnvBlock::rewriteCommand(get('deploy_path').'/shared'));

    if (test('[ -L {{deploy_path}}/current ]')) {
        run('cd {{current_path}} && {{bin/php}} artisan config:cache');
    }

    info('Wrote '.count($values).' settings to shared/.env: '.implode(', ', array_keys($values)));
});

desc('Gives offsite_reader_user read ACLs on shared/.env and offsite_acl_paths');
task('offsite:acl', function (): void {
    offsiteGuard();

    $user = get('offsite_reader_user');

    if (! is_string($user) || $user === '') {
        throw error("Set offsite_reader_user (the scheduler's user, e.g. www-data) first.");
    }

    $shared = get('deploy_path').'/shared';
    $acl = escapeshellarg("u:{$user}:rX");
    run('setfacl -m '.escapeshellarg("u:{$user}:r").' '.escapeshellarg("{$shared}/.env"));

    foreach ((array) get('offsite_acl_paths', []) as $path) {
        $target = escapeshellarg($shared.'/'.ltrim((string) $path, '/'));
        run("if [ -e {$target} ]; then setfacl -R -m {$acl} {$target} && find {$target} -type d -exec setfacl -d -m {$acl} {} +; fi");
    }

    writeln(run('getfacl -p '.escapeshellarg("{$shared}/.env").' 2>/dev/null || true'));
});

desc('Installs the schedule:run cron line (via contrib/crontab.php when loaded); refuses a second scheduler unless --force');
task('offsite:scheduler', function (): void {
    offsiteGuard();

    $deployPath = (string) get('deploy_path');
    $paths = array_values(array_unique([$deployPath, run('cd {{deploy_path}} && pwd -P')]));
    $marker = parse((string) get('offsite_cron_marker'));

    $existing = [
        ...SchedulerDetector::find(SchedulerDetector::parse(run(SchedulerDetector::gatherCommand())), $paths),
        ...SchedulerDetector::foreignCrontabLines(run('crontab -l 2>/dev/null || true'), $paths, $marker),
    ];

    if ($existing !== [] && ! (input()->hasOption('force') && input()->getOption('force'))) {
        throw error("schedule:run already runs for this app:\n  ".implode("\n  ", $existing)."\nAnother line would run every scheduled command twice. Use --force to install anyway.");
    }

    $umask = get('offsite_cron_umask');
    $line = SchedulerDetector::crontabLine(
        end($paths).'/current',
        parse('{{bin/php}}'),
        $marker,
        is_string($umask) ? $umask : null,
    );

    if (Deployer::get()->tasks->has('crontab:sync')) {
        // contrib/crontab.php keeps its own marked section; our marker comment rides along.
        add('crontab:jobs', [$line]);
        invoke('crontab:sync');
    } else {
        run('(crontab -l 2>/dev/null | grep -vF '.escapeshellarg($marker).'; echo '.escapeshellarg($line).') | crontab -');
    }

    writeln(run('crontab -l'));
});

desc('Runs offsite:doctor on the server');
task('offsite:doctor', function (): void {
    offsiteArtisan('offsite:doctor');
});

desc('Takes an off-site backup now');
task('offsite:run', function (): void {
    offsiteArtisan('backup:run --no-interaction', 3600);
});

desc('Lists the off-site backups');
task('offsite:list', function (): void {
    offsiteArtisan('backup:list');
});

desc('Downloads the newest off-site backup and test-restores it locally (never on the server)');
task('offsite:verify', function (): void {
    offsiteGuard();

    $env = ['OFFSITE_BACKUP_NAME' => (string) get('offsite_name'), ...offsiteResolveSecrets()];

    runLocally(
        (string) get('offsite_verify_command').' --name='.escapeshellarg((string) get('offsite_name')),
        timeout: 3600,
        env: $env,
        forceOutput: true,
    );
})->once();
