<?php

use Jothamlec\OffsiteBackup\Support\Preset;
use Jothamlec\OffsiteBackup\Support\SharedPath;

it('makes include and exclude paths absolute against the shared path', function () {
    $offsite = ['shared_path' => '/srv/app/shared', 'include' => ['.', 'storage/app', '/etc/extra/'], 'exclude' => ['storage/logs']];

    expect(Preset::include($offsite, '/srv/app/releases/3'))->toBe(['/srv/app/shared', '/srv/app/shared/storage/app', '/etc/extra'])
        ->and(Preset::exclude($offsite, '/srv/app/releases/3'))->toBe([
            '/srv/app/shared/storage/logs',
            '/srv/app/releases/3/vendor',
            '/srv/app/releases/3/node_modules',
        ]);
});

it('detects Deployer\'s shared dir from a releases/N base path', function () {
    $root = $this->sandboxPath('deploy');
    mkdir($root.'/releases/7', 0777, true);
    mkdir($root.'/shared');
    symlink($root.'/releases/7', $root.'/current');

    expect(SharedPath::resolve(null, $root.'/current'))->toBe(realpath($root.'/shared'))
        ->and(SharedPath::resolve(null, $root.'/releases/7'))->toBe(realpath($root.'/shared'))
        ->and(SharedPath::resolve('/explicit/', $root.'/current'))->toBe('/explicit');
});

it('falls back to base_path outside a Deployer layout', function () {
    expect(SharedPath::resolve(null, $this->sandboxPath('shared')))->toBe($this->sandboxPath('shared'));

    mkdir($this->sandboxPath('other/releases/1'), 0777, true);
    // releases/1 without a sibling shared/ isn't a Deployer layout.
    expect(SharedPath::resolve(null, $this->sandboxPath('other/releases/1')))->toBe($this->sandboxPath('other/releases/1'));
});

it('lists connections explicitly and never falls back to DB_CONNECTION', function () {
    expect(Preset::connections(['connections' => ['pgsql', '', 7, 'sqlite']]))->toBe(['pgsql', 'sqlite'])
        ->and(Preset::connections([]))->toBe([]);
});

it('defaults the temporary directory to storage/app/backup-temp', function () {
    expect(Preset::temporaryDirectory([]))->toBe(storage_path('app/backup-temp'))
        ->and(Preset::temporaryDirectory(['temporary_directory' => '/tmp/x']))->toBe('/tmp/x');
});

it('merges a published config over the package defaults', function () {
    $published = $this->sandboxPath('published.php');
    file_put_contents($published, "<?php return ['name' => 'shop', 'connections' => ['pgsql']];");

    $config = Preset::load($published);

    expect($config['name'])->toBe('shop')
        ->and($config['connections'])->toBe(['pgsql'])
        ->and($config['schedule']['timezone'])->toBe('UTC')
        ->and(Preset::load($this->sandboxPath('missing.php'))['include'])->toBe(['.']);
});
