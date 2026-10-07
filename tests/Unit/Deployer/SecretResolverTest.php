<?php

use Jothamlec\OffsiteBackup\Deployer\SecretResolver;

function resolverWith(array &$commands, array $outputs = [], array $env = []): SecretResolver
{
    return new SecretResolver(
        function (string $command) use (&$commands, $outputs): string {
            $commands[] = $command;

            return $outputs[$command] ?? throw new RuntimeException('exit 1');
        },
        fn (string $name): string|false => $env[$name] ?? false,
    );
}

it('resolves every kind of source', function () {
    $commands = [];
    $file = $this->sandboxPath('secret.txt');
    file_put_contents($file, "from-file\n");

    $resolver = resolverWith($commands, [
        "op read 'op://Ops/shop backup/archivePassword'" => "op-secret\n",
        "doppler secrets get 'B2_KEY' --plain" => 'doppler-secret',
    ], ['LOCAL_VAR' => 'from-env']);

    expect($resolver->resolveAll([
        'A' => 'env:LOCAL_VAR',
        'B' => 'op://Ops/shop backup/archivePassword',
        'C' => 'doppler:B2_KEY',
        'D' => "file:{$file}",
        'E' => 'my-bucket',
        'F' => 'literal:op://not-a-reference',
        'G' => fn (): string => 'from-closure',
        'H' => null,
    ]))->toBe([
        'A' => 'from-env',
        'B' => 'op-secret',
        'C' => 'doppler-secret',
        'D' => 'from-file',
        'E' => 'my-bucket',
        'F' => 'op://not-a-reference',
        'G' => 'from-closure',
        'H' => '',
    ])->and($commands)->toHaveCount(2);
});

it('fails on a missing required source', function (string $source, string $message) {
    $commands = [];

    expect(fn () => resolverWith($commands)->resolve($source, 'KEY'))->toThrow(RuntimeException::class, $message);
})->with([
    ['env:NOPE', 'KEY: the local environment variable NOPE isn\'t set.'],
    ['op://v/i/f', "KEY: `op read 'op://v/i/f'` failed"],
    ['file:/nonexistent/secret', "KEY: can't read /nonexistent/secret."],
]);

it('resolves an optional source to an empty string when it fails', function () {
    $commands = [];

    expect(resolverWith($commands)->resolve('op://v/i/heartbeat?'))->toBe('')
        ->and(resolverWith($commands)->resolve('env:NOPE?'))->toBe('')
        // A literal ending in "?" is just a literal.
        ->and(resolverWith($commands)->resolve('really?'))->toBe('really?');
});

it('quotes references in commands', function () {
    expect(SecretResolver::command("op://v/it's/f"))->toBe("op read 'op://v/it'\\''s/f'")
        ->and(SecretResolver::command('my-bucket'))->toBeNull();
});
