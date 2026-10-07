<?php

use Jothamlec\OffsiteBackup\Deployer\AclPlan;

function facl(string $path, string $owner, string $group, string ...$entries): string
{
    return "# file: {$path}\n# owner: {$owner}\n# group: {$group}\n".implode("\n", $entries)."\n\n";
}

it('never narrows an existing rwx entry', function () {
    $plan = new AclPlan('www-data', ['www-data']);
    $plan->add(facl('/srv/shared/storage', 'deploy', 'deploy', 'user::rwx', 'user:www-data:rwx', 'group::r-x', 'mask::rwx', 'other::---', 'default:user::rwx', 'default:user:www-data:rwx', 'default:group::r-x', 'default:mask::rwx', 'default:other::---'), directories: true);
    $plan->add(facl('/srv/shared/storage/app/a.png', 'deploy', 'deploy', 'user::rw-', 'user:www-data:rwx', 'group::r--', 'mask::rwx', 'other::---'), directories: false);

    expect($plan->changes())->toBe([])
        ->and($plan->readableCount())->toBe(2)
        ->and($plan->changeCount())->toBe(0);
});

it('keeps write access the user has through its group', function () {
    $plan = new AclPlan('www-data', ['www-data']);
    $plan->add(facl('/srv/shared/storage', 'deploy', 'www-data', 'user::rwx', 'group::rwx', 'other::---'), directories: true);
    $plan->add(facl('/srv/shared/storage/x.log', 'deploy', 'www-data', 'user::rw-', 'group::rw-', 'other::---'), directories: false);

    // Already readable: no access entry. The new default entry carries the group's rwx.
    expect($plan->changes())->toBe(['d:u:www-data:rwx' => ['/srv/shared/storage']]);
});

it('adds read where the user has none, and widens a narrower entry', function () {
    $plan = new AclPlan('www-data', ['www-data']);
    $plan->add(facl('/srv/shared/.env', 'deploy', 'deploy', 'user::rw-', 'group::---', 'other::---'), directories: false);
    $plan->add(facl('/srv/shared/storage/w.txt', 'deploy', 'deploy', 'user::rw-', 'user:www-data:-w-', 'group::---', 'mask::-w-', 'other::---'), directories: false);
    $plan->add(facl('/srv/shared/storage', 'deploy', 'deploy', 'user::rwx', 'group::---', 'other::---', 'default:user::rwx', 'default:user:www-data:--x', 'default:group::---', 'default:mask::--x', 'default:other::---'), directories: true);

    expect($plan->changes())->toBe([
        'd:u:www-data:rx' => ['/srv/shared/storage'],
        'u:www-data:r' => ['/srv/shared/.env'],
        'u:www-data:rw' => ['/srv/shared/storage/w.txt'],
        'u:www-data:rx' => ['/srv/shared/storage'],
    ]);
});

it('honours the mask and named groups', function () {
    $plan = new AclPlan('www-data', ['www-data', 'web']);
    // web may rw but the mask allows only w: www-data can't read yet, and keeps its w.
    $plan->add(facl('/s/f', 'deploy', 'deploy', 'user::rw-', 'group::---', 'group:web:rw-', 'mask::-w-', 'other::---'), directories: false);
    // Readable through other.
    $plan->add(facl('/s/g', 'deploy', 'deploy', 'user::rw-', 'group::---', 'other::r--'), directories: false);

    expect($plan->changes())->toBe(['u:www-data:rw' => ['/s/f']]);
});

it('warns instead of adding an entry for a path the user owns', function () {
    $plan = new AclPlan('www-data', ['www-data']);
    $plan->add(facl('/s/own', 'www-data', 'www-data', 'user::-w-', 'group::r--', 'other::r--'), directories: false);

    expect($plan->changes())->toBe([])
        ->and($plan->warnings()[0])->toContain('www-data owns /s/own');
});

it('decodes escaped names and parses id output', function () {
    $acl = AclPlan::parse(facl('/s/a\\040b', 'deploy', 'deploy', 'user::rw-', 'user:www-data:r--	#effective:r--', 'group::---', 'other::---'));

    expect(array_keys($acl))->toBe(['/s/a b'])
        ->and($acl['/s/a b']['entries']['user:www-data'])->toBe(4)
        ->and(AclPlan::parseGroups("www-data adm\n"))->toBe(['www-data', 'adm']);
});
