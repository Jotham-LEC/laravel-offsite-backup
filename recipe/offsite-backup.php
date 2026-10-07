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
 *       'OFFSITE_BACKUP_HEARTBEAT_URL' => 'op://Ops/my-app-backup/heartbeat?',
 *   ]);
 *   set('offsite_env_extra', ['OFFSITE_BACKUP_CONNECTIONS' => 'pgsql']);
 *
 *   dep offsite:env production        write the settings into shared/.env, then config:cache
 *   dep offsite:acl production        give offsite_reader_user read ACLs, never narrowing any (optional)
 *   dep offsite:scheduler production  install the scheduler's cron line (refuses duplicates)
 *   dep offsite:doctor production     pre-flight checks on the server
 *   dep offsite:run production        take a backup now
 *   dep offsite:list production       list the backups on the disk
 *   dep offsite:verify production     download, decrypt and test-restore here, not on the server
 *
 * All op:// secrets are read with one `op inject`, so 1Password asks for approval once per task.
 */

use Jothamlec\OffsiteBackup\Deployer\AclPlan;
use Jothamlec\OffsiteBackup\Deployer\EnvBlock;
use Jothamlec\OffsiteBackup\Deployer\LocalCommand;
use Jothamlec\OffsiteBackup\Deployer\SchedulerDetector;
use Jothamlec\OffsiteBackup\Deployer\SecretResolver;
use Jothamlec\OffsiteBackup\Deployer\Stage;
use Symfony\Component\Console\Input\InputOption;

foreach (['AclPlan', 'EnvBlock', 'LocalCommand', 'SecretResolver', 'SchedulerDetector', 'Stage'] as $offsiteHelper) {
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

// A local KEY=VALUE file (mode 600) whose values are used instead of resolving offsite_secrets.
set('offsite_secrets_file', null);

// Non-secret ENV key => value pairs written with the secrets, e.g. OFFSITE_BACKUP_CONNECTIONS,
// OFFSITE_BACKUP_TIME, OFFSITE_BACKUP_HEARTBEAT_FORMAT, OFFSITE_MIRROR_SOURCE.
set('offsite_env_extra', []);

// offsite:env removes these [start, end] marked blocks from shared/.env (hand-rolled setups).
set('offsite_legacy_markers', EnvBlock::LEGACY_MARKERS);

// offsite:acl: the user that runs the scheduler when it isn't the deploy user (e.g. www-data).
set('offsite_reader_user', null);

// offsite:acl: paths under shared/ that user needs to read, besides .env (e.g. ['storage']).
// Only read (and directory search) is added where missing; existing permissions are never narrowed.
set('offsite_acl_paths', []);

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
 * OFFSITE_BACKUP_NAME, offsite_env_extra and the resolved offsite_secrets (later keys win).
 *
 * @return array<string, string>
 */
function offsiteEnvValues(): array
{
    $file = get('offsite_secrets_file');

    return [
        'OFFSITE_BACKUP_NAME' => (string) get('offsite_name'),
        ...LocalCommand::stringValues((array) get('offsite_env_extra', [])),
        ...(new SecretResolver)->resolveAll(
            (array) get('offsite_secrets', []),
            is_string($file) && $file !== '' ? parse($file) : null,
        ),
    ];
}

/**
 * The absolute, symlink-free {{deploy_path}}/shared. The cd is deliberately unquoted so the
 * remote shell expands a leading ~; use the result (never a quoted deploy_path) afterwards.
 */
function offsiteSharedPath(): string
{
    $path = trim(run('cd {{deploy_path}}/shared && pwd -P'));

    if (! str_starts_with($path, '/')) {
        throw error("Could not resolve deploy_path/shared to an absolute path (got '{$path}').");
    }

    return $path;
}

function offsiteArtisan(string $command, int $timeout = 300): void
{
    offsiteGuard();
    writeln(run("cd {{current_path}} && {{bin/php}} artisan {$command}", timeout: $timeout));
}

desc('Writes the off-site backup settings into shared/.env (in place) and caches the config');
task('offsite:env', function (): void {
    offsiteGuard();

    $shared = offsiteSharedPath();
    $values = [
        'OFFSITE_BACKUP_SHARED_PATH' => $shared,
        ...offsiteEnvValues(),
    ];

    $block = EnvBlock::render($values);
    $local = tempnam(sys_get_temp_dir(), 'offsite-env');
    chmod($local, 0600);
    file_put_contents($local, $block);
    $remote = $shared.'/.env.offsite-block';

    try {
        try {
            upload($local, $remote);
        } finally {
            unlink($local);
        }

        run(EnvBlock::rewriteCommand($shared, legacy: (array) get('offsite_legacy_markers', [])));
    } catch (\Throwable $e) {
        // The uploaded block holds the secrets; never leave it behind on the server.
        try {
            run('rm -f '.escapeshellarg($remote).' '.escapeshellarg($shared.'/.env.offsite-new'));
        } catch (\Throwable) {
            warning("Could not remove {$remote} (or .env.offsite-new); delete it by hand.");
        }

        throw $e;
    }

    if (test('[ -L {{deploy_path}}/current ]')) {
        run('cd {{current_path}} && {{bin/php}} artisan config:cache');
    }

    info('Wrote '.count($values).' settings to shared/.env: '.implode(', ', array_keys($values)));
});

desc('Gives offsite_reader_user read access to shared/.env and offsite_acl_paths, adding ACL entries only where it lacks them');
task('offsite:acl', function (): void {
    offsiteGuard();

    $user = get('offsite_reader_user');

    if (! is_string($user) || $user === '') {
        throw error("Set offsite_reader_user (the scheduler's user, e.g. www-data) first.");
    }

    if (! test('command -v getfacl >/dev/null 2>&1 && command -v setfacl >/dev/null 2>&1')) {
        throw error('offsite:acl needs getfacl and setfacl on the server (the acl package).');
    }

    $shared = offsiteSharedPath();
    $groups = AclPlan::parseGroups(run('id -nG '.escapeshellarg($user)));
    $plan = new AclPlan($user, $groups);
    $targets = array_values(array_unique(['.env', ...array_map(
        fn (mixed $path): string => trim((string) $path, '/'),
        (array) get('offsite_acl_paths', []),
    )]));

    foreach ($targets as $path) {
        $target = escapeshellarg($shared.'/'.$path);

        if (! test("[ -e {$target} ]")) {
            warning("offsite:acl: {$shared}/{$path} doesn't exist; skipped.");

            continue;
        }

        // Symlinks are skipped: setfacl would follow them out of the tree.
        $plan->add(run("find {$target} -type d -exec getfacl -p -- {} +"), directories: true);
        $plan->add(run("find {$target} ! -type d ! -type l -exec getfacl -p -- {} +"), directories: false);
    }

    foreach ($plan->changes() as $spec => $paths) {
        foreach (array_chunk($paths, 200) as $chunk) {
            run('setfacl -m '.escapeshellarg($spec).' -- '.implode(' ', array_map('escapeshellarg', $chunk)));
        }

        info(count($paths)." path(s): {$spec}");
    }

    foreach ($plan->warnings() as $message) {
        warning($message);
    }

    info("offsite:acl {$user}: {$plan->readableCount()} path(s) already readable, {$plan->changeCount()} ACL entr(ies) added or widened; nothing narrowed.");
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

    // The local project, with the resolved settings in its environment (not on any command
    // line), so the local .env needs no production keys.
    $project = function_exists('Deployer\\Support\\deployer_root') ? Support\deployer_root() : (string) getcwd();
    LocalCommand::assertConfigNotCached($project);

    $command = (string) get('offsite_verify_command').' --name='.escapeshellarg((string) get('offsite_name'));
    writeln("<comment>[local]</comment> {$command}");

    $exit = LocalCommand::run($command, offsiteEnvValues(), function (string $buffer): void {
        output()->write($buffer);
    }, $project);

    if ($exit !== 0) {
        throw error("offsite:verify failed (exit code {$exit}).");
    }
})->once();
