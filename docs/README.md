# Livewire Strict - Documentation

Livewire Strict enforces additional security measures for your [Livewire](https://livewire.laravel.com) components, preventing common attack vectors that exploit Livewire's frontend-exposed surface.

## Installation

```bash
composer require wire-elements/livewire-strict
```

The package auto-registers its service provider via Laravel's package discovery.

## Quick Start

Enable all features at once in your `AppServiceProvider`:

```php
use WireElements\LivewireStrict\LivewireStrict;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        LivewireStrict::enableAll();
    }
}
```

Or enable features individually:

```php
LivewireStrict::lockProperties();
LivewireStrict::signedActions(ttl: 300);
```

## Features

| Feature | What it protects | Docs |
|---------|-----------------|------|
| [Locked Properties](locked-properties.md) | Prevents frontend from modifying public properties | [Read more →](locked-properties.md) |
| [Signed Actions](signed-actions.md) | Makes action calls tamper-proof with HMAC signatures | [Read more →](signed-actions.md) |

## How it works

Every Livewire request sends a JSON payload from the browser to the server. An attacker can craft these payloads manually to:

1. **Modify any public property** - e.g., changing `$price` or `$user_id`
2. **Alter action parameters** - e.g., changing `wire:click="delete(5)"` to `delete(999)`

Livewire Strict closes these gaps by requiring explicit opt-in for property modifications and cryptographic signing for sensitive action calls.

## Configuration

### Scoping to specific components

All features accept a `components` parameter to scope enforcement:

```php
// All components under App\Livewire (default)
LivewireStrict::lockProperties();

// Specific namespace
LivewireStrict::lockProperties(components: 'App\Livewire\Admin\*');

// Specific component
LivewireStrict::lockProperties(components: App\Livewire\Checkout::class);

// Multiple patterns
LivewireStrict::lockProperties(components: [
    'App\Livewire\Admin\*',
    'App\Livewire\Checkout',
]);
```

## Requirements

- PHP 8.1+
- Laravel 10, 11, or later
- Livewire 3.5+ or 4.0+
- A valid `APP_KEY` (required for signed actions)
