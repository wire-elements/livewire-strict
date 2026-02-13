<?php

namespace WireElements\LivewireStrict;

use Illuminate\Support\Arr;
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
     * @param  int|null  $ttl  Seconds until payloads expire. Use 0 or Signed::NO_EXPIRATION to disable expiration.
     */
    public static function signedActions($shouldSignActions = true, $components = ['App\Livewire\*'], $ttl = null)
    {
        SupportSignedActions::$enabled = $shouldSignActions;
        SupportSignedActions::$components = Arr::wrap($components);
        SupportSignedActions::$ttl = SupportSignedActions::normalizeTtl($ttl);
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
