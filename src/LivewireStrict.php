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

    public static function signedActions($shouldSignActions = true, $components = ['App\Livewire\*'], $ttl = null)
    {
        SupportSignedActions::$enabled = $shouldSignActions;
        SupportSignedActions::$components = Arr::wrap($components);
        SupportSignedActions::$ttl = $ttl;
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
