<?php

declare(strict_types=1);

use FancyCompliance\Categories;
use FancyCompliance\InvalidCategoryConfiguration;

it('always has a required necessary category, even when the host config omits it', function (): void {
    $categories = Categories::fromConfig([
        'analytics' => ['label' => 'x.label', 'description' => 'x.description', 'purposes' => []],
    ]);

    expect($categories->keys())->toBe(['necessary', 'analytics'])
        ->and($categories->get('necessary')->required)->toBeTrue()
        ->and($categories->get('analytics')->required)->toBeFalse()
        ->and($categories->optionalKeys())->toBe(['analytics']);
});

it('keeps necessary required when the host config tries to make it optional', function (): void {
    $categories = Categories::fromConfig([
        'necessary' => ['label' => 'n.label', 'description' => 'n.description', 'purposes' => [], 'required' => false],
    ]);

    expect($categories->get('necessary')->required)->toBeTrue()
        ->and($categories->optionalKeys())->toBe([]);
});

it('carries a label key, a description key and purposes for every category', function (): void {
    $category = Categories::fromConfig(defaultCategories())->get('analytics');

    expect($category->key)->toBe('analytics')
        ->and($category->label)->toBe('fancy-compliance::categories.analytics.label')
        ->and($category->description)->toBe('fancy-compliance::categories.analytics.description')
        ->and($category->purposes)->toBe(['fancy-compliance::categories.analytics.purposes.usage']);
});

it('refuses category keys that are not short lowercase identifiers', function (string $key): void {
    Categories::fromConfig([$key => ['label' => 'l', 'description' => 'd', 'purposes' => []]]);
})->with([
    'uppercase' => 'Analytics',
    'space' => 'ad tech',
    'too long' => str_repeat('a', 33),
    'empty' => '',
    'markup' => '<b>',
])->throws(InvalidCategoryConfiguration::class);

it('refuses a category without a label or description', function (): void {
    Categories::fromConfig(['analytics' => ['purposes' => []]]);
})->throws(InvalidCategoryConfiguration::class);

it('refuses more than 32 categories, so request bounds can rely on the count', function (): void {
    $config = [];
    for ($i = 0; $i < 33; $i++) {
        $config['c'.$i] = ['label' => 'l', 'description' => 'd', 'purposes' => []];
    }
    Categories::fromConfig($config);
})->throws(InvalidCategoryConfiguration::class);
