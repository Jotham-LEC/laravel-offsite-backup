<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Jothamlec\OffsiteBackup\Verify\BackupLocator;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

it('lists only the top-level zips: no recursive listing and no HEAD (mimeType) per object', function () {
    $root = $this->sandboxPath('disk');
    $adapter = new LocalFilesystemAdapter($root);
    Storage::set('strict', new class(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => true]) extends FilesystemAdapter
    {
        public function allFiles($directory = null)
        {
            throw new LogicException('listed recursively');
        }

        public function mimeType($path)
        {
            throw new LogicException("HEAD {$path}");
        }
    });

    $this->putBackup(['.env' => 'x'], '2026-10-01-03-00-00.zip');
    $this->putBackup(['.env' => 'x'], '2026-10-02-03-00-00.zip');
    mkdir($root.'/offsite-test/media/media', 0777, true);
    file_put_contents($root.'/offsite-test/media/media/a.png', 'a');
    file_put_contents($root.'/offsite-test/notes.txt', 'n');

    $backups = (new BackupLocator)->all('strict', 'offsite-test');

    expect($backups->map->path()->all())->toBe(['offsite-test/2026-10-02-03-00-00.zip', 'offsite-test/2026-10-01-03-00-00.zip']);
});
