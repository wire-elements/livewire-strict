<?php

namespace WireElements\LivewireStrict;

use Illuminate\Support\ServiceProvider;

class LivewireStrictServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function register(): void
    {
        app('livewire')->componentHook(Features\SupportLockedProperties\SupportLockedProperties::class);
    }
}
