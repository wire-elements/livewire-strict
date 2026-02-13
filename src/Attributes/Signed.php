<?php

namespace WireElements\LivewireStrict\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Signed
{
    public function __construct(
        public ?int $ttl = null,
    ) {}
}
