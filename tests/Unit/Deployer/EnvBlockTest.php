<?php

use Jothamlec\OffsiteBackup\Deployer\EnvBlock;

it('renders a marked block with single-quoted values', function () {
    expect(EnvBlock::render(['OFFSITE_BACKUP_NAME' => 'shop', 'BACKUP_ARCHIVE_PASSWORD' => 'p@ss w0rd$', 'EMPTY' => null]))->toBe(
        "# >>> offsite-backup (managed by `dep offsite:env`; edits are overwritten)\n"
        ."OFFSITE_BACKUP_NAME='shop'\n"
        ."BACKUP_ARCHIVE_PASSWORD='p@ss w0rd\$'\n"
        ."EMPTY=''\n"
        ."# <<< offsite-backup\n"
    );
});

it('rejects values .env single quotes can\'t hold', function (string $value) {
    EnvBlock::render(['KEY' => $value]);
})->with(["it's", "two\nlines"])->throws(InvalidArgumentException::class);

it('rejects invalid keys', function () {
    EnvBlock::render(['lower-case' => 'x']);
})->throws(InvalidArgumentException::class);

it('replaces an existing block instead of appending a second one', function () {
    $env = "APP_KEY=x\nDB_CONNECTION=pgsql";
    $once = EnvBlock::replaceIn($env, EnvBlock::render(['A' => '1']));
    $twice = EnvBlock::replaceIn($once."OTHER=after\n", EnvBlock::render(['A' => '2']));

    expect($once)->toBe("APP_KEY=x\nDB_CONNECTION=pgsql\n".EnvBlock::render(['A' => '1']))
        ->and($twice)->toBe("APP_KEY=x\nDB_CONNECTION=pgsql\nOTHER=after\n".EnvBlock::render(['A' => '2']))
        ->and(substr_count($twice, EnvBlock::START))->toBe(1);
});

it('rewrites .env in place, keeping its mode, and matches replaceIn', function () {
    $shared = $this->sandboxPath('srv shared');
    mkdir($shared);
    $original = "APP_KEY=x\nDB_CONNECTION=pgsql";
    file_put_contents($shared.'/.env', EnvBlock::replaceIn($original, EnvBlock::render(['A' => 'old'])));
    chmod($shared.'/.env', 0640);
    $inode = fileinode($shared.'/.env');

    $block = EnvBlock::render(['A' => 'new', 'B' => 'two']);
    file_put_contents($shared.'/.env.offsite-block', $block);
    exec(EnvBlock::rewriteCommand($shared).' 2>&1', $output, $exit);
    clearstatcache();

    expect($exit)->toBe(0)
        ->and(file_get_contents($shared.'/.env'))->toBe(EnvBlock::replaceIn($original, $block))
        ->and(fileperms($shared.'/.env') & 0777)->toBe(0640)
        ->and(fileinode($shared.'/.env'))->toBe($inode)
        ->and(file_exists($shared.'/.env.offsite-block'))->toBeFalse()
        ->and(file_exists($shared.'/.env.offsite-new'))->toBeFalse();
});
