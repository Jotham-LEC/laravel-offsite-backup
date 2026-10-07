<?php

use Jothamlec\OffsiteBackup\Deployer\Stage;

it('allows hosts whose stage label is listed', function () {
    expect(Stage::allowed(['stage' => 'production'], 'web1', ['production']))->toBeTrue()
        ->and(Stage::allowed(['stage' => 'staging'], 'web1', ['production']))->toBeFalse()
        ->and(Stage::allowed([], 'prod', ['prod']))->toBeTrue()
        ->and(Stage::allowed(['stage' => 'staging'], 'web1', []))->toBeTrue();
});

it('slugs the application name for the bucket folder', function () {
    expect(Stage::slug('example.com.my'))->toBe('example.com.my')
        ->and(Stage::slug('My Shop'))->toBe('my-shop')
        ->and(Stage::slug('***'))->toBe('laravel');
});
