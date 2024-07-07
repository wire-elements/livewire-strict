<?php

namespace WireElements\LivewireStrict\Features\SupportLockedProperties;

use Livewire\ComponentHook;
use Livewire\Features\SupportAttributes\AttributeLevel;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use WireElements\LivewireStrict\Attributes\Unlocked;

class SupportLockedProperties extends ComponentHook
{
    public static bool $locked = false;

    public function update($propertyName, $fullPath, $newValue)
    {
        if (self::$locked === false) {
            return;
        }

        $componentIsUnlocked = $this->component
            ->getAttributes()
            ->whereInstanceOf(Unlocked::class)
            ->filter(fn (Unlocked $attribute) => $attribute->getLevel() === AttributeLevel::ROOT)
            ->isNotEmpty();

        if ($componentIsUnlocked) {
            return;
        }

        $propertyIsUnlocked = $this->component
            ->getAttributes()
            ->whereInstanceOf(Unlocked::class)
            ->filter(fn (Unlocked $attribute) => $attribute->getSubName() === $propertyName && $attribute->getLevel() === AttributeLevel::PROPERTY)
            ->isNotEmpty();

        throw_unless($propertyIsUnlocked, CannotUpdateLockedPropertyException::class, $propertyName);
    }
}
