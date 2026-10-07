<?php

use Symfony\Component\Process\Process;

/**
 * Runs recipe/offsite-backup.php with the installed Deployer (^8) against localhost.
 */
function runDep(string $sandbox, string $task, array $settings, array $env = [], ?string $deployPath = null): Process
{
    $root = dirname(__DIR__, 2);
    $deployFile = $sandbox.'/deploy.php';
    file_put_contents($deployFile, "<?php\nnamespace Deployer;\n"
        .'require '.var_export($root.'/vendor/autoload.php', true).";\n"
        .'require '.var_export($root.'/recipe/offsite-backup.php', true).";\n"
        ."set('application', 'Shop App');\n"
        ."localhost('production')->set('labels', ['stage' => 'production'])->set('deploy_path', ".var_export($deployPath ?? $sandbox.'/srv', true).");\n"
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

it('expands a ~ in deploy_path for offsite:env', function () {
    file_put_contents($this->sandboxPath('srv/shared/.env'), "APP_KEY=x\n");

    $process = runDep($this->sandbox, 'offsite:env', [
        'offsite_secrets' => ['B2_BUCKET' => 'backups'],
    ], ['HOME' => $this->sandbox], '~/srv');

    $shared = realpath($this->sandboxPath('srv/shared'));

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and(file_get_contents($this->sandboxPath('srv/shared/.env')))->toContain("OFFSITE_BACKUP_SHARED_PATH='{$shared}'\nOFFSITE_BACKUP_NAME='shop-app'\nB2_BUCKET='backups'\n")
        ->and(file_exists($this->sandboxPath('srv/shared/.env.offsite-block')))->toBeFalse();
});

it('removes the uploaded block when the merge fails', function () {
    mkdir($this->sandboxPath('srv/shared/.env'));

    $process = runDep($this->sandbox, 'offsite:env', [
        'offsite_secrets' => ['BACKUP_ARCHIVE_PASSWORD' => 'env:TEST_ARCHIVE_PASSWORD'],
    ], ['TEST_ARCHIVE_PASSWORD' => 'do-not-leave-me']);

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_exists($this->sandboxPath('srv/shared/.env.offsite-block')))->toBeFalse()
        ->and(file_exists($this->sandboxPath('srv/shared/.env.offsite-new')))->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('do-not-leave-me');
});

/**
 * Fake getfacl, setfacl and id on a PATH, so the ACL tests need no ACL support or www-data user.
 * getfacl prints owner deploy, group deploy, mode 600/700, plus the lines in acl/<basename>.
 *
 * @return array<string, string>
 */
function fakeAclTools(string $sandbox): array
{
    @mkdir($sandbox.'/bin');
    @mkdir($sandbox.'/acl');
    $scripts = [
        'setfacl' => "#!/bin/sh\necho \"\$@\" >> ".escapeshellarg($sandbox.'/setfacl.log')."\n",
        'id' => "#!/bin/sh\necho www-data\n",
        'getfacl' => "#!/bin/sh\nseen=\nfor p in \"\$@\"; do\n  if [ -z \"\$seen\" ]; then [ \"\$p\" = -- ] && seen=1; continue; fi\n"
            ."  if [ -d \"\$p\" ]; then u=rwx; else u=rw-; fi\n"
            ."  printf '# file: %s\\n# owner: deploy\\n# group: deploy\\nuser::%s\\ngroup::---\\nother::---\\n' \"\$p\" \"\$u\"\n"
            .'  f='.escapeshellarg($sandbox.'/acl/')."\"\$(basename \"\$p\")\"; [ -f \"\$f\" ] && cat \"\$f\"\n  echo\ndone\n",
    ];

    foreach ($scripts as $name => $script) {
        file_put_contents($sandbox.'/bin/'.$name, $script);
        chmod($sandbox.'/bin/'.$name, 0755);
    }

    return ['HOME' => $sandbox, 'PATH' => $sandbox.'/bin:'.getenv('PATH')];
}

it('expands a ~ in deploy_path for offsite:acl, and by default only touches .env', function () {
    file_put_contents($this->sandboxPath('srv/shared/.env'), "APP_KEY=x\n");
    mkdir($this->sandboxPath('srv/shared/storage'));

    $process = runDep($this->sandbox, 'offsite:acl', [
        'offsite_reader_user' => 'www-data',
    ], fakeAclTools($this->sandbox), '~/srv');

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and(file_get_contents($this->sandboxPath('setfacl.log')))->toBe('-m u:www-data:r -- '.realpath($this->sandboxPath('srv/shared')).'/.env'."\n");
});

it('never narrows existing permissions in offsite_acl_paths', function () {
    $shared = $this->sandboxPath('srv/shared');
    file_put_contents($shared.'/.env', "APP_KEY=x\n");
    mkdir($shared.'/storage/app', 0777, true);
    file_put_contents($shared.'/storage/app/writable.png', 'x');
    file_put_contents($shared.'/storage/app/private.png', 'x');
    $env = fakeAclTools($this->sandbox);
    // www-data may already write under storage: those entries must stay rwx / rw.
    file_put_contents($this->sandboxPath('acl/storage'), "user:www-data:rwx\nmask::rwx\ndefault:user:www-data:rwx\n");
    file_put_contents($this->sandboxPath('acl/writable.png'), "user:www-data:rw-\nmask::rw-\n");
    file_put_contents($this->sandboxPath('acl/.env'), "user:www-data:r--\nmask::r--\n");

    $process = runDep($this->sandbox, 'offsite:acl', [
        'offsite_reader_user' => 'www-data',
        'offsite_acl_paths' => ['storage'],
    ], $env);

    $real = realpath($shared);
    $log = file_get_contents($this->sandboxPath('setfacl.log'));

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($log)->toBe(
            "-m d:u:www-data:rx -- {$real}/storage/app\n"
            ."-m u:www-data:r -- {$real}/storage/app/private.png\n"
            ."-m u:www-data:rx -- {$real}/storage/app\n"
        )
        ->and($log)->not->toContain('writable.png')
        ->and($process->getOutput())->toContain('nothing narrowed');
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
