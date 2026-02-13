<?php

namespace WireElements\LivewireStrict\Attributes;

use Livewire\Features\SupportAttributes\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Signed extends Attribute
{
    public function __construct(
        public ?int $ttl = null,
    ) {}
}
