<?php

namespace WireElements\LivewireStrict\Attributes;

use Livewire\Features\SupportAttributes\Attribute;
use Livewire\Features\SupportAttributes\AttributeLevel;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Signed extends Attribute
{
    /**
     * @param  int|null  $ttl  Seconds until the signed payload expires.
     *                         - null: inherit the global TTL (default)
     *                         - 0: never expire, even if a global TTL is set
     *                         - positive int: override the global TTL with this value
     */
    public function __construct(
        public ?int $ttl = null,
    ) {
        self::validateTtl($this->ttl);
    }

    /**
     * Validate that a TTL value is non-negative.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateTtl(?int $ttl): void
    {
        if ($ttl !== null && $ttl < 0) {
            throw new \InvalidArgumentException("TTL must be a non-negative integer, got: {$ttl}");
        }
    }

    /**
     * Resolve the effective TTL for a method, considering per-method overrides.
     */
    public static function resolveMethodTtl(object $component, string $method, ?int $globalTtl): ?int
    {
        $signed = $component->getAttributes()
            ->whereInstanceOf(self::class)
            ->filter(fn (self $attribute) => $attribute->getLevel() === AttributeLevel::METHOD && $attribute->getName() === $method)
            ->first();

        if ($signed?->ttl !== null) {
            return $signed->ttl;
        }

        return $globalTtl;
    }
}
