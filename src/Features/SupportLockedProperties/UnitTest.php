<?php

namespace WireElements\LivewireStrict\Features\SupportLockedProperties;

use Livewire\Component;
use Livewire\Livewire;
use WireElements\LivewireStrict\Attributes\Unlocked;
use WireElements\LivewireStrict\LivewireStrict;

class UnitTest extends \Tests\TestCase
{
    public function test_cant_update_globally_locked_property()
    {
        $this->expectExceptionMessage(
            'Cannot update locked property: [count]'
        );

        LivewireStrict::lockProperties(components: 'WireElements\*');

        Livewire::test(new class extends TestComponent
        {
            public $count = 1;

            public function increment()
            {
                $this->count++;
            }
        })
            ->assertSetStrict('count', 1)
            ->set('count', 2);
    }

    public function test_can_update_unlocked_property()
    {
        LivewireStrict::lockProperties();

        Livewire::test(new class extends TestComponent
        {
            #[Unlocked]
            public $count = 1;
        })
            ->assertSetStrict('count', 1)
            ->set('count', 2);
    }

    public function test_can_update_unlocked_component()
    {
        LivewireStrict::lockProperties();

        Livewire::test(new #[Unlocked] class extends TestComponent
        {
            public $count = 1;
        })
            ->assertSetStrict('count', 1)
            ->set('count', 2);
    }

    public function test_only_enabled_for_specific_namespace()
    {
        $this->expectExceptionMessage(
            'Cannot update locked property: [count]'
        );

        LivewireStrict::lockProperties(components: 'WireElements\*');

        Livewire::test(new class extends SpecificComponent
        {
            public $count = 1;

            public function increment()
            {
                $this->count++;
            }
        })
            ->assertSetStrict('count', 1)
            ->set('count', 2);
    }

    public function test_only_enabled_for_specific_components()
    {
        $this->expectExceptionMessage(
            'Cannot update locked property: [count]'
        );

        LivewireStrict::lockProperties(components: 'WireElements\LivewireStrict\Features\SupportLockedProperties\SpecificComponent*');

        Livewire::test(new class extends SpecificComponent
        {
            public $count = 1;

            public function increment()
            {
                $this->count++;
            }
        })
            ->assertSetStrict('count', 1)
            ->set('count', 2);
    }

    public function test_it_ignores_other_components()
    {
        LivewireStrict::lockProperties(components: 'App/*');

        Livewire::test(new class extends SpecificComponent
        {
            public $count = 1;

            public function increment()
            {
                $this->count++;
            }
        })
            ->assertSetStrict('count', 1)
            ->set('count', 2);
    }
}

class TestComponent extends Component
{
    public function render()
    {
        return '<div></div>';
    }
}

class SpecificComponent extends TestComponent
{
}
