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

it('removes a hand-rolled legacy block, on the server and in replaceIn', function () {
    $shared = $this->sandboxPath('shared-legacy');
    mkdir($shared);
    $original = "APP_KEY=x\n"
        ."# >>> off-site backup (managed by `dep backup:env`)\n"
        ."BACKUP_NAME='shop'\nB2_KEY_ID='k'\nB2_APPLICATION_KEY='a.b*c[d]^\$/'\n"
        ."# <<< off-site backup\n"
        ."MAIL_MAILER=smtp\n";
    file_put_contents($shared.'/.env', $original);

    $block = EnvBlock::render(['OFFSITE_BACKUP_NAME' => 'shop']);
    file_put_contents($shared.'/.env.offsite-block', $block);
    exec('('.EnvBlock::rewriteCommand($shared, legacy: EnvBlock::LEGACY_MARKERS).') 2>&1', $output, $exit);

    $expected = "APP_KEY=x\nMAIL_MAILER=smtp\n".$block;

    expect($exit)->toBe(0)
        ->and(file_get_contents($shared.'/.env'))->toBe($expected)
        ->and(EnvBlock::replaceIn($original, $block, EnvBlock::LEGACY_MARKERS))->toBe($expected);
});

it('handles custom legacy markers with regex characters', function () {
    $shared = $this->sandboxPath('shared-custom');
    mkdir($shared);
    $markers = [['## [backup] start (*)', '## [backup] end $.']];
    $original = "A=1\n## [backup] start (*) old\nB=2\n## [backup] end $. old\nC=3\n";
    file_put_contents($shared.'/.env', $original);
    file_put_contents($shared.'/.env.offsite-block', $block = EnvBlock::render(['D' => '4']));

    exec(EnvBlock::rewriteCommand($shared, legacy: $markers).' 2>&1', $output, $exit);

    expect($exit)->toBe(0)
        ->and(file_get_contents($shared.'/.env'))->toBe("A=1\nC=3\n".$block)
        ->and(EnvBlock::replaceIn($original, $block, $markers))->toBe("A=1\nC=3\n".$block);
});

it('refuses to rewrite .env when a legacy block has no end marker', function () {
    $shared = $this->sandboxPath('shared-unbalanced');
    mkdir($shared);
    $original = "APP_KEY=x\n# >>> off-site backup\nBACKUP_NAME='shop'\nDB_PASSWORD=keep-me\n";
    file_put_contents($shared.'/.env', $original);
    file_put_contents($shared.'/.env.offsite-block', EnvBlock::render(['A' => '1']));

    exec('('.EnvBlock::rewriteCommand($shared, legacy: EnvBlock::LEGACY_MARKERS).') 2>&1', $output, $exit);

    expect($exit)->toBe(1)
        ->and(implode("\n", $output))->toContain("different number of '# >>> off-site backup'")
        ->and(file_get_contents($shared.'/.env'))->toBe($original)
        ->and(fn () => EnvBlock::replaceIn($original, 'x', EnvBlock::LEGACY_MARKERS))->toThrow(RuntimeException::class);
});

it('validates legacy marker pairs', function (mixed $legacy) {
    EnvBlock::markerPairs($legacy);
})->with([
    [[['only-start']]],
    [['# >>> x']],
    [[['', 'end']]],
    [[["a\nb", 'end']]],
])->throws(InvalidArgumentException::class);
