<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions;

use Illuminate\Support\Carbon;
use WireElements\LivewireStrict\Attributes\Signed;
use WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions\ExpiredSignedActionException;
use WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions\InvalidSignedActionException;

class SignedPayload
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Constructor is public to allow tests to forge payloads for security assertions.
     * Arbitrary instances cannot produce valid signatures without the APP_KEY.
     */
    public function __construct(
        public readonly string $componentId,
        public readonly string $method,
        public readonly array $params = [],
        public readonly ?int $expiry = null,
    ) {}

    /**
     * Create a signed payload for a component, resolving per-method TTL overrides.
     *
     * Note: Signed::resolveMethodTtl() may return 0 (meaning "never expire").
     * The `$ttl > 0` check normalizes 0 to null so the payload has no expiry.
     */
    public static function forComponent(object $component, string $method, mixed ...$params): self
    {
        $ttl = Signed::resolveMethodTtl($component, $method, SupportSignedActions::$ttl);

        return new self(
            componentId: $component->getId(),
            method: $method,
            params: $params,
            expiry: $ttl > 0 ? Carbon::now()->timestamp + $ttl : null,
        );
    }

    /**
     * Verify an encoded payload against a component and return a SignedPayload instance.
     *
     * Verification order: structure → types → HMAC → expiry → component ID.
     * This order avoids timing oracles (HMAC checked before component ID)
     * and skips unnecessary work on structurally invalid payloads.
     *
     * Note: valid payloads can be replayed on the same component instance.
     * This is by design - Blade buttons render a fixed payload that must
     * remain usable across multiple clicks. Use TTL to limit the replay window.
     *
     * @throws InvalidSignedActionException
     * @throws ExpiredSignedActionException
     */
    public static function verify(string $encodedPayload, object $component): self
    {
        $decoded = json_decode(base64_decode($encodedPayload, true), true);

        throw_unless(
            is_array($decoded) && isset($decoded['sig'], $decoded['method'], $decoded['params'], $decoded['id']),
            InvalidSignedActionException::class,
        );

        // Validate types of the decoded payload to avoid TypeError and ensure predictable failures.
        throw_if(
            !is_scalar($decoded['id'])
                || !is_scalar($decoded['method'])
                || !is_scalar($decoded['sig'])
                || !is_array($decoded['params'])
                || (array_key_exists('exp', $decoded) && !is_int($decoded['exp'])),
            InvalidSignedActionException::class,
        );

        $sig = (string) $decoded['sig'];

        $instance = new self(
            componentId: (string) $decoded['id'],
            method: (string) $decoded['method'],
            params: $decoded['params'],
            expiry: $decoded['exp'] ?? null,
        );

        throw_unless(hash_equals($instance->sign(), $sig), InvalidSignedActionException::class, $instance->method);

        throw_if(
            isset($instance->expiry) && Carbon::now()->timestamp > $instance->expiry,
            ExpiredSignedActionException::class,
            $instance->method,
        );

        throw_unless($instance->componentId === $component->getId(), InvalidSignedActionException::class, $instance->method);

        return $instance;
    }

    /**
     * Encode the payload into a signed, base64-encoded string.
     */
    public function encode(): string
    {
        return base64_encode(json_encode(array_merge($this->payloadData(), ['sig' => $this->sign()])));
    }

    /**
     * Get the wire action string for use in Blade templates.
     */
    public function toAction(): string
    {
        return "__callSigned('{$this->encode()}')";
    }

    /**
     * Get the application signing key, ensuring it is set.
     *
     * Derives a purpose-specific key via HMAC to provide domain separation.
     * This prevents cross-system signature confusion if other subsystems
     * also use the raw APP_KEY with hash_hmac('sha256', ...).
     *
     * @throws \RuntimeException
     */
    private static function signingKey(): string
    {
        throw_unless(config('app.key'), \RuntimeException::class, 'No application key set. Signed actions require an APP_KEY to be configured.');

        return hash_hmac('sha256', 'livewire-strict:signed-actions', config('app.key'));
    }

    /**
     * Build the canonical payload data array used for signing.
     */
    private function payloadData(): array
    {
        return array_filter([
            'id' => $this->componentId,
            'method' => $this->method,
            'params' => $this->params,
            'exp' => $this->expiry,
        ], fn ($value) => $value !== null);
    }

    /**
     * Compute the HMAC-SHA256 signature for this payload.
     */
    private function sign(): string
    {
        return hash_hmac('sha256', json_encode($this->payloadData(), self::JSON_FLAGS), self::signingKey());
    }
}
