<?php

namespace WireElements\LivewireStrict\Attributes;

use Livewire\Features\SupportAttributes\Attribute;
use WireElements\LivewireStrict\Features\Concerns\NormalizesTtl;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Signed extends Attribute
{
    use NormalizesTtl;

    /**
     * Explicitly disable expiration, even if a global TTL is set.
     */
    public const NO_EXPIRATION = 0;

    public function __construct(
        public ?int $ttl = null,
    ) {
        static::normalizeTtl($ttl);
    }
}
