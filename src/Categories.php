<?php

declare(strict_types=1);

namespace FancyCompliance;

/**
 * The host's consent categories, validated once.
 *
 * `necessary` always exists and is always required: it is added when the host
 * leaves it out, and a host config that marks it optional is overruled rather
 * than obeyed. A banner that let someone refuse the cookies a site needs to
 * remember the refusal would be a banner that could never take "no" for an
 * answer.
 */
final class Categories
{
    public const NECESSARY = 'necessary';

    /** Keys are short lowercase identifiers. The pattern is anchored with a fixed bound: no backtracking. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    public const MAX = 32;

    /** @param array<string, Category> $categories */
    private function __construct(private readonly array $categories) {}

    /** @param array<array-key, mixed> $config */
    public static function fromConfig(array $config): self
    {
        if (! array_key_exists(self::NECESSARY, $config)) {
            $config = [self::NECESSARY => [
                'label' => 'fancy-compliance::categories.necessary.label',
                'description' => 'fancy-compliance::categories.necessary.description',
                'purposes' => ['fancy-compliance::categories.necessary.purposes.session', 'fancy-compliance::categories.necessary.purposes.consent'],
            ], ...$config];
        }
        if (count($config) > self::MAX) {
            throw new InvalidCategoryConfiguration('At most '.self::MAX.' consent categories may be configured.');
        }

        $categories = [];
        foreach ($config as $key => $definition) {
            $key = (string) $key;
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidCategoryConfiguration("Consent category key [{$key}] must match ".self::KEY_PATTERN.'.');
            }
            if (! is_array($definition)) {
                throw new InvalidCategoryConfiguration("Consent category [{$key}] must be an array.");
            }
            foreach (['label', 'description'] as $field) {
                if (! isset($definition[$field]) || ! is_string($definition[$field]) || $definition[$field] === '') {
                    throw new InvalidCategoryConfiguration("Consent category [{$key}] needs a {$field} translation key.");
                }
            }
            $purposes = $definition['purposes'] ?? [];
            if (! is_array($purposes) || ! array_is_list($purposes) || array_filter($purposes, fn ($p) => ! is_string($p) || $p === '') !== []) {
                throw new InvalidCategoryConfiguration("Consent category [{$key}] purposes must be a list of translation keys.");
            }

            $categories[$key] = new Category(
                key: $key,
                label: $definition['label'],
                description: $definition['description'],
                purposes: $purposes,
                required: $key === self::NECESSARY,
            );
        }

        return new self($categories);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->categories);
    }

    /** @return list<string> every category a person may refuse */
    public function optionalKeys(): array
    {
        return array_values(array_map(
            fn (Category $c) => $c->key,
            array_filter($this->categories, fn (Category $c) => ! $c->required),
        ));
    }

    /** @return list<string> */
    public function requiredKeys(): array
    {
        return array_values(array_map(
            fn (Category $c) => $c->key,
            array_filter($this->categories, fn (Category $c) => $c->required),
        ));
    }

    public function has(string $key): bool
    {
        return isset($this->categories[$key]);
    }

    public function get(string $key): Category
    {
        return $this->categories[$key] ?? throw new InvalidConsentInput('unknown_category', "Unknown consent category [{$key}].");
    }

    /** @return list<Category> */
    public function all(): array
    {
        return array_values($this->categories);
    }
}
