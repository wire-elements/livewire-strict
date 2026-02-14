<?php

namespace WireElements\LivewireStrict;

use Illuminate\Support\Arr;
use WireElements\LivewireStrict\Attributes\Signed;
use WireElements\LivewireStrict\Features\SupportLockedProperties\SupportLockedProperties;
use WireElements\LivewireStrict\Features\SupportSignedActions\SupportSignedActions;

class LivewireStrict
{
    public static function lockProperties($shouldLockProperties = true, $components = ['App\Livewire\*'])
    {
        SupportLockedProperties::$locked = $shouldLockProperties;
        SupportLockedProperties::$components = Arr::wrap($components);
    }

    /**
     * Enable signed actions for the given components.
     *
     * @param  bool  $shouldSignActions
     * @param  string|string[]  $components  Component class or wildcard pattern(s).
     * @param  int|null  $ttl  Seconds until payloads expire.
     *                         - null: no expiration (default)
     *                         - 0: no expiration (same as null)
     *                         - positive int: payloads expire after this many seconds
     */
    public static function signedActions($shouldSignActions = true, $components = ['App\Livewire\*'], $ttl = null)
    {
        Signed::validateTtl($ttl);

        SupportSignedActions::$ttl = $ttl > 0 ? $ttl : null;
        SupportSignedActions::$enabled = $shouldSignActions;
        SupportSignedActions::$components = Arr::wrap($components);
    }

    public static function enableAll($condition = true)
    {
        if (! $condition) {
            return;
        }

        self::lockProperties();
        self::signedActions();
    }
}
