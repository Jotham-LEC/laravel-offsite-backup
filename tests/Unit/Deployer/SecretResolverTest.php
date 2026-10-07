<?php

use Jothamlec\OffsiteBackup\Deployer\SecretResolver;

/**
 * A resolver whose commands are faked: `op inject` substitutes $vault[reference] into the
 * template like the real one (failing on an unknown reference); other commands answer from
 * $outputs. Every command is recorded in $commands as [argv, stdin].
 */
function resolverWith(array &$commands, array $outputs = [], array $env = [], array $vault = []): SecretResolver
{
    return new SecretResolver(
        function (array $argv, ?string $input) use (&$commands, $outputs, $vault): string {
            $commands[] = [$argv, $input];

            if ($argv === ['op', 'inject']) {
                return preg_replace_callback('/\{\{ (op:\/\/[^}]+) \}\}/', fn (array $m): string => $vault[$m[1]]
                    ?? throw new RuntimeException("[ERROR] could not resolve {$m[1]}"), (string) $input);
            }

            return $outputs[implode(' ', $argv)] ?? throw new RuntimeException('exit 1');
        },
        fn (string $name): string|false => $env[$name] ?? false,
    );
}

it('resolves every kind of source', function () {
    $commands = [];
    $file = $this->sandboxPath('secret.txt');
    file_put_contents($file, "from-file\n");

    $resolver = resolverWith($commands, [
        'doppler secrets get B2_KEY --plain' => 'doppler-secret',
    ], ['LOCAL_VAR' => 'from-env'], ['op://Ops/shop/archivePassword' => 'op-secret']);

    expect($resolver->resolveAll([
        'A' => 'env:LOCAL_VAR',
        'B' => 'op://Ops/shop/archivePassword',
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

it('reads every 1Password reference with a single op inject over stdin', function () {
    $commands = [];
    $resolver = resolverWith($commands, vault: [
        'op://Personal/abc123/archivePassword_shop' => 'p1',
        'op://Personal/abc123/keyID' => 'k1',
        'op://Personal/abc123/applicationKey' => 'k2',
        'op://Personal/abc123/heartbeat' => 'https://kuma.example.com/api/push/x',
    ]);

    $values = $resolver->resolveAll([
        'BACKUP_ARCHIVE_PASSWORD' => 'op://Personal/abc123/archivePassword_shop',
        'B2_BUCKET' => 'backups',
        'B2_ACCESS_KEY_ID' => 'op://Personal/abc123/keyID',
        'B2_SECRET_ACCESS_KEY' => 'op://Personal/abc123/applicationKey',
        'OFFSITE_BACKUP_HEARTBEAT_URL' => 'op://Personal/abc123/heartbeat?',
        'FROM_CLOSURE' => fn (): string => 'op://Personal/abc123/keyID',
    ]);

    expect($values)->toBe([
        'BACKUP_ARCHIVE_PASSWORD' => 'p1',
        'B2_BUCKET' => 'backups',
        'B2_ACCESS_KEY_ID' => 'k1',
        'B2_SECRET_ACCESS_KEY' => 'k2',
        'OFFSITE_BACKUP_HEARTBEAT_URL' => 'https://kuma.example.com/api/push/x',
        'FROM_CLOSURE' => 'k1',
    ])
        ->and($commands)->toHaveCount(1)
        ->and($commands[0][0])->toBe(['op', 'inject'])
        ->and($commands[0][1])->toContain('{{ op://Personal/abc123/archivePassword_shop }}')
        ->and($commands[0][1])->not->toContain('heartbeat?');
});

it('round-trips values with special characters through op inject', function (string $secret) {
    $commands = [];
    $resolver = resolverWith($commands, vault: ['op://v/i/a' => $secret, 'op://v/i/b' => 'second']);

    expect($resolver->resolveAll(['A' => 'op://v/i/a', 'B' => 'op://v/i/b']))->toBe(['A' => $secret, 'B' => 'second']);
})->with([
    'quotes and shell characters' => ['p"a$s`s\'w\\o;r|d&{x}'],
    'braces and markers' => ['{{ op://v/i/b }}<<0>>'],
    'leading and trailing spaces' => ['  padded  '],
    'a newline' => ["two\nlines"],
    'unicode' => ['pässwörd✓'],
    'empty' => [''],
]);

it('drops a failing optional reference after one retry, and fails on a required one', function () {
    $commands = [];
    $resolver = resolverWith($commands, vault: ['op://v/i/password' => 'pw']);

    expect($resolver->resolveAll(['P' => 'op://v/i/password', 'H' => 'op://v/i/missing?']))->toBe(['P' => 'pw', 'H' => ''])
        ->and($commands)->toHaveCount(2);

    $commands = [];

    expect(fn () => $resolver->resolveAll(['P' => 'op://v/i/missing', 'Q' => 'op://v/i/password']))
        ->toThrow(RuntimeException::class, '`op inject` failed for P, Q: [ERROR] could not resolve op://v/i/missing');
});

it('loads a secrets file instead of 1Password', function () {
    $commands = [];
    $file = $this->sandboxPath('offsite.secrets');
    file_put_contents($file, implode("\n", [
        '# off-site backup secrets',
        'BACKUP_ARCHIVE_PASSWORD=\'p@ss "w$rd"\'',
        'export B2_ACCESS_KEY_ID="k1"',
        '',
        'B2_SECRET_ACCESS_KEY=k2=with=equals',
        'EXTRA_ONLY_IN_FILE=x',
    ]));
    chmod($file, 0600);

    $values = resolverWith($commands)->resolveAll([
        'BACKUP_ARCHIVE_PASSWORD' => 'op://v/i/archivePassword',
        'B2_ACCESS_KEY_ID' => 'op://v/i/keyID',
        'B2_SECRET_ACCESS_KEY' => 'op://v/i/applicationKey',
        'B2_BUCKET' => 'backups',
    ], $file);

    expect($values)->toBe([
        'BACKUP_ARCHIVE_PASSWORD' => 'p@ss "w$rd"',
        'B2_ACCESS_KEY_ID' => 'k1',
        'B2_SECRET_ACCESS_KEY' => 'k2=with=equals',
        'B2_BUCKET' => 'backups',
        'EXTRA_ONLY_IN_FILE' => 'x',
    ])->and($commands)->toBe([]);
});

it('refuses a secrets file other users can read', function (int $mode) {
    $commands = [];
    $file = $this->sandboxPath('offsite.secrets');
    file_put_contents($file, "A=b\n");
    chmod($file, $mode);

    resolverWith($commands)->resolveAll(['A' => 'op://v/i/a'], $file);
})->with([0640, 0604, 0644])->throws(RuntimeException::class, 'readable by other users');

it('fails on a missing or malformed secrets file', function () {
    $commands = [];
    $file = $this->sandboxPath('bad.secrets');
    file_put_contents($file, "not a pair\n");
    chmod($file, 0600);

    expect(fn () => resolverWith($commands)->resolveAll([], $this->sandboxPath('nope')))->toThrow(RuntimeException::class, "can't read")
        ->and(fn () => resolverWith($commands)->resolveAll([], $file))->toThrow(RuntimeException::class, "line 1 of {$file} isn't KEY=VALUE");
});

it('fails on a missing required source', function (string $source, string $message) {
    $commands = [];

    expect(fn () => resolverWith($commands)->resolve($source, 'KEY'))->toThrow(RuntimeException::class, $message);
})->with([
    ['env:NOPE', 'KEY: the local environment variable NOPE isn\'t set.'],
    ['op://v/i/f', "KEY: `op read 'op://v/i/f'` failed"],
    ['file:/nonexistent/secret', "KEY: can't read /nonexistent/secret."],
]);

it('resolves a single op:// source with op read', function () {
    $commands = [];
    $resolver = resolverWith($commands, ['op read op://v/i/f' => "value\n"]);

    expect($resolver->resolve('op://v/i/f'))->toBe('value')
        ->and($commands)->toBe([[['op', 'read', 'op://v/i/f'], null]]);
});

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

it('refuses references that would break the inject template', function () {
    SecretResolver::injectTemplate(['A' => 'op://v/i/f}}'], 'n');
})->throws(RuntimeException::class);

it('pipes the template to a real op process on stdin', function () {
    $bin = $this->sandboxPath('bin');
    mkdir($bin);
    // A stand-in for `op inject`: substitutes two references in what it reads from stdin.
    file_put_contents($bin.'/op', "#!/bin/sh\n[ \"\$1\" = inject ] || exit 2\nsed -e 's#{{ op://v/i/a }}#it'\\''s \$ecret#' -e 's#{{ op://v/i/b }}#k2#'\n");
    chmod($bin.'/op', 0755);
    $path = getenv('PATH');
    putenv("PATH={$bin}:{$path}");

    try {
        expect((new SecretResolver)->resolveAll(['A' => 'op://v/i/a', 'B' => 'op://v/i/b']))->toBe(['A' => "it's \$ecret", 'B' => 'k2']);
    } finally {
        putenv("PATH={$path}");
    }
});
