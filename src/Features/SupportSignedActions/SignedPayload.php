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
     * @throws \RuntimeException
     */
    private static function signingKey(): string
    {
        throw_unless(config('app.key'), \RuntimeException::class, 'No application key set. Signed actions require an APP_KEY to be configured.');

        return config('app.key');
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

        $method = $decoded['method'];

        $payloadData = array_filter([
            'id' => $decoded['id'],
            'method' => $method,
            'params' => $decoded['params'],
            'exp' => $decoded['exp'] ?? null,
        ], fn ($value) => $value !== null);

        $expectedSig = hash_hmac('sha256', json_encode($payloadData, self::JSON_FLAGS), self::signingKey());

        throw_unless(hash_equals($expectedSig, $decoded['sig']), InvalidSignedActionException::class, $method);

        throw_if(
            isset($decoded['exp']) && Carbon::now()->timestamp > $decoded['exp'],
            ExpiredSignedActionException::class,
            $method,
        );

        throw_unless($decoded['id'] === $component->getId(), InvalidSignedActionException::class, $method);

        return new self(
            componentId: $decoded['id'],
            method: $method,
            params: $decoded['params'],
            expiry: $decoded['exp'] ?? null,
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
