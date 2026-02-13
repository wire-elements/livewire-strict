# Locked Properties

Locks all public properties on Livewire components by default, preventing the frontend from modifying them. Properties must be explicitly unlocked with the `#[Unlocked]` attribute.

## The Problem

In Livewire, every public property is writable from the frontend. A malicious user can open browser DevTools and send a crafted request to change any public property - even ones not bound to any input.

```php
class Invoice extends Component
{
    public int $invoiceId = 1;
    public float $total = 99.99;
    public string $status = 'pending';
}
```

An attacker could send a Livewire update to set `$total = 0` or `$status = 'paid'` without any UI interaction.

## Setup

```php
use WireElements\LivewireStrict\LivewireStrict;

// In your AppServiceProvider::boot()
LivewireStrict::lockProperties();
```

## Usage

Once enabled, **all public properties are locked by default**. Any frontend attempt to modify them throws a `CannotUpdateLockedPropertyException`.

### Unlocking specific properties

Use the `#[Unlocked]` attribute on properties that should be writable from the frontend:

```php
use WireElements\LivewireStrict\Attributes\Unlocked;

class SearchUsers extends Component
{
    #[Unlocked]
    public string $query = '';       // ✅ Frontend can update (e.g., wire:model)

    public array $results = [];      // 🔒 Locked - only server can modify
    public int $totalCount = 0;      // 🔒 Locked - only server can modify
}
```

```blade
{{-- This works because $query is #[Unlocked] --}}
<input wire:model="query" type="text" placeholder="Search...">

{{-- These are display-only, protected from tampering --}}
<p>{{ $totalCount }} results found</p>
```

### Unlocking an entire component

If a component needs all properties writable, apply `#[Unlocked]` at the class level:

```php
use WireElements\LivewireStrict\Attributes\Unlocked;

#[Unlocked]
class ContactForm extends Component
{
    public string $name = '';      // ✅ Unlocked
    public string $email = '';     // ✅ Unlocked
    public string $message = '';   // ✅ Unlocked
}
```

### Server-side updates still work

Locked properties can still be modified by server-side code. Only frontend updates are blocked.

```php
class Counter extends Component
{
    public int $count = 0;  // 🔒 Locked from frontend

    public function increment()
    {
        $this->count++;  // ✅ This works - server-side update
    }
}
```

## Scoping

```php
// Only components under App\Livewire\Admin
LivewireStrict::lockProperties(components: 'App\Livewire\Admin\*');

// Only a specific component
LivewireStrict::lockProperties(components: App\Livewire\Checkout::class);
```
