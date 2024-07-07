<?php

namespace WireElements\LivewireStrict;

use WireElements\LivewireStrict\Features\SupportLockedProperties\SupportLockedProperties;

class LivewireStrict
{
    public static function lockProperties($condition = true)
    {
        SupportLockedProperties::$locked = $condition;
    }

    public static function enableAll($condition = true)
    {
        if (! $condition) {
            return;
        }

        self::lockProperties();
    }
}
