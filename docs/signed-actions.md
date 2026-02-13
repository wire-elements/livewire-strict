# Signed Actions

Makes Livewire action calls tamper-proof by signing the method name, parameters, and component instance with HMAC-SHA256. Prevents attackers from modifying action parameters in the DOM.

## The Problem

When you write `wire:click="delete({{ $post->id }})"`, Livewire renders the method call directly in the HTML. An attacker can use browser DevTools to change the argument before clicking:

```html
<!-- Original -->
<button wire:click="delete(5)">Delete</button>

<!-- Attacker changes it to -->
<button wire:click="delete(999)">Delete</button>
```

The server has no way to know the parameter was tampered with.

## Setup

```php
use WireElements\LivewireStrict\LivewireStrict;

// In your AppServiceProvider::boot()
LivewireStrict::signedActions();

// With expiration (recommended)
LivewireStrict::signedActions(ttl: 300); // Payloads expire after 5 minutes
```

## Usage

### 1. Mark sensitive methods with `#[Signed]`

```php
use WireElements\LivewireStrict\Attributes\Signed;

class PostList extends Component
{
    #[Signed]
    public function delete(int $postId)
    {
        Post::findOrFail($postId)->delete();
    }

    #[Signed]
    public function updateStatus(int $postId, string $status)
    {
        Post::findOrFail($postId)->update(['status' => $status]);
    }

    // Regular methods don't need signing
    public function loadMore()
    {
        $this->page++;
    }
}
```

### 2. Use `@livewireAction` in Blade

Replace inline method calls with the `@livewireAction` directive:

```blade
{{-- Instead of this (tamperable): --}}
<button wire:click="delete({{ $post->id }})">Delete</button>

{{-- Use this (tamper-proof): --}}
<button wire:click="@livewireAction('delete', $post->id)">Delete</button>

{{-- Multiple parameters work too: --}}
<button wire:click="@livewireAction('updateStatus', $post->id, 'published')">
    Publish
</button>
```

## How It Works

1. **At render time**, `@livewireAction` generates an HMAC-SHA256 signature over the method name, parameters, and component ID using your `APP_KEY`
2. The signed payload is encoded as a base64 string and rendered as `__callSigned('eyJ...')`
3. **When clicked**, the `SupportSignedActions` hook intercepts the call, verifies the HMAC, checks the component ID matches, and only then executes the method
4. Direct calls to `#[Signed]` methods (e.g., `$wire.call('delete', 5)`) are **blocked**

### What's protected

| Attack | Result |
|--------|--------|
| Change parameters in DOM | ❌ HMAC verification fails |
| Call signed method directly via JS | ❌ Blocked - must use signed payload |
| Replay payload on different component | ❌ Component ID mismatch |
| Tamper with expiration timestamp | ❌ HMAC verification fails |
| Use expired payload | ❌ `ExpiredSignedActionException` thrown |

## Payload Expiration

Set a TTL to limit how long signed payloads remain valid:

```php
// Payloads expire after 5 minutes
LivewireStrict::signedActions(ttl: 300);

// No expiration (default)
LivewireStrict::signedActions();
```

With a TTL, payloads include a signed timestamp. After expiration, the action is rejected with an `ExpiredSignedActionException`. The timestamp is part of the HMAC, so attackers cannot extend it.

### Per-method TTL

You can override the global TTL on individual methods using the `ttl` parameter on `#[Signed]`:

```php
use WireElements\LivewireStrict\Attributes\Signed;

class OrderManager extends Component
{
    // Uses the global TTL
    #[Signed]
    public function archive(int $orderId) { ... }

    // Stricter: expires after 30 seconds
    #[Signed(ttl: 30)]
    public function refund(int $orderId, int $amount) { ... }

    // No expiration, even if global TTL is set
    #[Signed(ttl: 0)]
    public function viewDetails(int $orderId) { ... }
}
```

Per-method TTL takes precedence over the global TTL. If a method has no `ttl` parameter, the global TTL is used.

**Choosing a TTL:** Consider how long a page stays open before a user interacts. For admin panels, 5-15 minutes is reasonable. For long-lived dashboards, use a longer TTL or disable expiration.

## Scoping

```php
// Only admin components
LivewireStrict::signedActions(components: 'App\Livewire\Admin\*');

// Specific component with 10-minute expiry
LivewireStrict::signedActions(
    components: App\Livewire\PostList::class,
    ttl: 600
);
```

## When to Use Signed Actions

**Use `#[Signed]` for methods where the parameters are security-sensitive:**
- Deleting records: `delete($id)`
- Changing roles/permissions: `updateRole($userId, $role)`
- Financial operations: `refund($orderId, $amount)`
- Any action where a tampered parameter leads to unauthorized behavior

**You don't need `#[Signed]` for (but it still works if you do):**
- Methods with no parameters: `loadMore()`, `refresh()`
- Methods where parameters come from locked server-side state
- Methods that re-validate authorization internally regardless of input
