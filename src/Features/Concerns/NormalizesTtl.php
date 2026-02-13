<?php

namespace WireElements\LivewireStrict\Features\Concerns;

trait NormalizesTtl
{
    /**
     * Validate and normalize a TTL value.
     * Rejects negative values, treats 0 as null (no expiration).
     */
    public static function normalizeTtl(?int $ttl): ?int
    {
        if ($ttl !== null && $ttl < 0) {
            throw new \InvalidArgumentException('TTL must be a non-negative integer, got: ' . $ttl);
        }

        return ($ttl === 0) ? null : $ttl;
    }
}
