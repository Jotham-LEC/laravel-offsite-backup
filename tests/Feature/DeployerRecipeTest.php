<?php

use Symfony\Component\Process\Process;

/**
 * Runs recipe/offsite-backup.php with the installed Deployer (^8) against localhost.
 */
function runDep(string $sandbox, string $task, array $settings, array $env = []): Process
{
    $root = dirname(__DIR__, 2);
    $deployFile = $sandbox.'/deploy.php';
    file_put_contents($deployFile, "<?php\nnamespace Deployer;\n"
        .'require '.var_export($root.'/vendor/autoload.php', true).";\n"
        .'require '.var_export($root.'/recipe/offsite-backup.php', true).";\n"
        ."set('application', 'Shop App');\n"
        ."localhost('production')->set('labels', ['stage' => 'production'])->set('deploy_path', ".var_export($sandbox.'/srv', true).");\n"
        .implode('', array_map(fn (string $key, mixed $value): string => "set('{$key}', ".var_export($value, true).");\n", array_keys($settings), $settings)));

    $process = new Process([PHP_BINARY, $root.'/vendor/bin/dep', '-f', $deployFile, $task, 'production', '--no-interaction'], $sandbox, $env, null, 120);
    $process->run();

    return $process;
}

beforeEach(function () {
    mkdir($this->sandboxPath('srv/shared'), 0777, true);
});

it('writes the block into shared/.env, replacing a hand-rolled one, with the extra keys', function () {
    $env = $this->sandboxPath('srv/shared/.env');
    file_put_contents($env, "APP_KEY=x\n# >>> off-site backup (managed by `dep backup:env`)\nBACKUP_NAME='shop'\nB2_KEY_ID='old'\n# <<< off-site backup\nMAIL_MAILER=log\n");
    chmod($env, 0640);

    $process = runDep($this->sandbox, 'offsite:env', [
        'offsite_secrets' => ['BACKUP_ARCHIVE_PASSWORD' => 'env:TEST_ARCHIVE_PASSWORD', 'B2_BUCKET' => 'backups'],
        'offsite_env_extra' => ['OFFSITE_BACKUP_CONNECTIONS' => 'pgsql', 'OFFSITE_BACKUP_TIME' => '02:30'],
    ], ['TEST_ARCHIVE_PASSWORD' => 'p@ss $word']);

    $shared = realpath($this->sandboxPath('srv/shared'));

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and(file_get_contents($env))->toBe("APP_KEY=x\nMAIL_MAILER=log\n"
            ."# >>> offsite-backup (managed by `dep offsite:env`; edits are overwritten)\n"
            ."OFFSITE_BACKUP_SHARED_PATH='{$shared}'\n"
            ."OFFSITE_BACKUP_NAME='shop-app'\n"
            ."OFFSITE_BACKUP_CONNECTIONS='pgsql'\n"
            ."OFFSITE_BACKUP_TIME='02:30'\n"
            ."BACKUP_ARCHIVE_PASSWORD='p@ss \$word'\n"
            ."B2_BUCKET='backups'\n"
            ."# <<< offsite-backup\n")
        ->and(fileperms($env) & 0777)->toBe(0640);
});

it('runs offsite:verify locally with the secrets in its environment, never on a command line', function () {
    $secrets = $this->sandboxPath('offsite.secrets');
    file_put_contents($secrets, "B2_SECRET_ACCESS_KEY=s3cr3t-value\n");
    chmod($secrets, 0600);

    $process = runDep($this->sandbox, 'offsite:verify', [
        'offsite_secrets' => ['B2_BUCKET' => 'backups', 'B2_SECRET_ACCESS_KEY' => 'op://never/read/this'],
        'offsite_secrets_file' => $secrets,
        'offsite_env_extra' => ['OFFSITE_BACKUP_CONNECTIONS' => 'pgsql'],
        'offsite_verify_command' => 'printf "%s|%s|%s|%s|" "$OFFSITE_BACKUP_NAME" "$B2_BUCKET" "$B2_SECRET_ACCESS_KEY" "$OFFSITE_BACKUP_CONNECTIONS"; echo',
    ]);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain("shop-app|backups|s3cr3t-value|pgsql|--name=shop-app\n")
        ->and($process->getOutput())->toContain('[local] printf');
});

it('fails offsite:verify on a non-zero exit without printing the secrets', function () {
    $process = runDep($this->sandbox, 'offsite:verify', [
        'offsite_secrets' => ['BACKUP_ARCHIVE_PASSWORD' => 'env:TEST_ARCHIVE_PASSWORD'],
        'offsite_verify_command' => 'exit 3;',
    ], ['TEST_ARCHIVE_PASSWORD' => 'do-not-print-me']);

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->not->toBe(0)
        ->and($output)->toContain('offsite:verify failed (exit code 3)')
        ->and($output)->not->toContain('do-not-print-me');
});

it('refuses hosts outside offsite_stages', function () {
    $process = runDep($this->sandbox, 'offsite:verify', ['offsite_stages' => ['staging']]);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('limited to offsite_stages [staging]');
});
