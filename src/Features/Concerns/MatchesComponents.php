<?php

namespace WireElements\LivewireStrict\Features\Concerns;

trait MatchesComponents
{
    protected function checkIsRequired(): bool
    {
        foreach (static::$components as $component) {
            if (str($component)->contains('*') && str($this->component::class)->is($component)) {
                return true;
            }

            if ($component === $this->component::class) {
                return true;
            }
        }

        return false;
    }
}
