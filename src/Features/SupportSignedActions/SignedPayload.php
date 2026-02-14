<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions;

use Illuminate\Support\Carbon;
use WireElements\LivewireStrict\Attributes\Signed;
use WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions\ExpiredSignedActionException;
use WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions\InvalidSignedActionException;

class SignedPayload
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        public readonly string $componentId,
        public readonly string $method,
        public readonly array $params = [],
        public readonly ?int $expiry = null,
    ) {}

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
     * Create a signed payload for a component, resolving per-method TTL overrides.
     */
    public static function forComponent(object $component, string $method, mixed ...$params): self
    {
        $ttl = Signed::resolveMethodTtl($component, $method, SupportSignedActions::$ttl);

        return new self(
            componentId: $component->getId(),
            method: $method,
            params: $params,
            expiry: $ttl ? Carbon::now()->timestamp + $ttl : null,
        );
    }

    /**
     * Verify an encoded payload against a component and return a SignedPayload instance.
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
        $hasInvalidTypes = !is_scalar($decoded['id'])
            || !is_scalar($decoded['method'])
            || !is_scalar($decoded['sig'])
            || !is_array($decoded['params'])
            || (array_key_exists('exp', $decoded) && !is_int($decoded['exp']));

        if ($hasInvalidTypes) {
            throw new InvalidSignedActionException('');
        }

        $id = (string) $decoded['id'];
        $method = (string) $decoded['method'];
        $sig = (string) $decoded['sig'];
        $params = $decoded['params'];
        $exp = $decoded['exp'] ?? null;

        $payloadData = array_filter([
            'id' => $id,
            'method' => $method,
            'params' => $params,
            'exp' => $exp,
        ], fn ($value) => $value !== null);

        $expectedSig = hash_hmac('sha256', json_encode($payloadData, self::JSON_FLAGS), self::signingKey());

        throw_unless(hash_equals($expectedSig, $sig), InvalidSignedActionException::class, $method);

        throw_if(
            isset($exp) && Carbon::now()->timestamp > $exp,
            ExpiredSignedActionException::class,
            $method,
        );

        throw_unless($id === $component->getId(), InvalidSignedActionException::class, $method);

        return new self(
            componentId: $id,
            method: $method,
            params: $params,
            expiry: $exp,
        );
    }

    /**
     * Encode the payload into a signed, base64-encoded string.
     */
    public function encode(): string
    {
        $payloadData = array_filter([
            'id' => $this->componentId,
            'method' => $this->method,
            'params' => $this->params,
            'exp' => $this->expiry,
        ], fn ($value) => $value !== null);

        $signature = hash_hmac('sha256', json_encode($payloadData, self::JSON_FLAGS), self::signingKey());

        return base64_encode(json_encode(array_merge($payloadData, ['sig' => $signature])));
    }

    /**
     * Get the wire action string for use in Blade templates.
     */
    public function toAction(): string
    {
        return "__callSigned('{$this->encode()}')";
    }
}
