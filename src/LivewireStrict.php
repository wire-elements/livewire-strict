<?php

namespace WireElements\LivewireStrict;

use Illuminate\Support\Arr;
use WireElements\LivewireStrict\Features\SupportLockedProperties\SupportLockedProperties;

class LivewireStrict
{
    public static function lockProperties($shouldLockProperties = true, $components = ['App\Livewire\*'])
    {
        SupportLockedProperties::$locked = $shouldLockProperties;
        SupportLockedProperties::$components = Arr::wrap($components);
    }

    public static function enableAll($condition = true)
    {
        if (! $condition) {
            return;
        }

        self::lockProperties();
    }
}
