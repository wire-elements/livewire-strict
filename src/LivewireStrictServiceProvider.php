<?php

namespace WireElements\LivewireStrict;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class LivewireStrictServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        app('livewire')->componentHook(Features\SupportLockedProperties\SupportLockedProperties::class);
        app('livewire')->componentHook(Features\SupportSignedActions\SupportSignedActions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('livewireAction', function ($expression) {
            return "<?php echo \\WireElements\\LivewireStrict\\Features\\SupportSignedActions\\SupportSignedActions::generateSignedActionForComponent(\$__livewire, $expression); ?>";
        });
    }
}
