<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions;

use Livewire\ComponentHook;
use Illuminate\Support\Carbon;
use WireElements\LivewireStrict\Attributes\Signed;
use WireElements\LivewireStrict\Features\Concerns\MatchesComponents;
use WireElements\LivewireStrict\Features\Concerns\NormalizesTtl;

class SupportSignedActions extends ComponentHook
{
    use MatchesComponents;
    use NormalizesTtl;

    public static bool $enabled = false;

    public static array $components = [];

    /**
     * Time-to-live in seconds for signed payloads. Null means no expiration.
     */
    public static ?int $ttl = null;

    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        if (self::$enabled === false) {
            return;
        }

        if (! $this->checkIsRequired()) {
            return;
        }

        // Handle signed action calls
        if ($method === '__callSigned') {
            // Guard: ensure the component doesn't have an actual __callSigned method
            if (method_exists($this->component, '__callSigned')) {
                throw new \LogicException(
                    'Component [' . $this->component::class . '] defines a __callSigned method, which collides with the internal signed-action hook.'
                );
            }

            if (! isset($params[0]) || ! is_string($params[0])) {
                throw new InvalidSignedActionException('__callSigned');
            }

            $decoded = $this->verifyAndDecode($params[0]);
            $result = $this->component->{$decoded['method']}(...$decoded['params']);
            $returnEarly($result);

            return;
        }

        // Block direct calls to #[Signed] methods
        if ($this->methodIsSigned($method)) {
            throw new InvalidSignedActionException($method);
        }
    }

    protected function methodIsSigned(string $method): bool
    {
        if (! method_exists($this->component, $method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($this->component, $method);

        return $reflection->isPublic() && ! $reflection->isStatic() && ! empty($reflection->getAttributes(Signed::class));
    }

    /**
     * Get the TTL for a specific method. Per-method TTL overrides the global TTL.
     */
    public static function getMethodTtl(object $component, string $method): ?int
    {
        if (! method_exists($component, $method)) {
            return static::$ttl;
        }

        $reflection = new \ReflectionMethod($component, $method);
        $attributes = $reflection->getAttributes(Signed::class);

        if (! empty($attributes)) {
            $signed = $attributes[0]->newInstance();

            if ($signed->ttl !== null) {
                return static::normalizeTtl($signed->ttl);
            }
        }

        return static::$ttl;
    }

    protected function verifyAndDecode(string $encodedPayload): array
    {
        $decoded = json_decode(base64_decode($encodedPayload, true), true);

        if (! $decoded || ! isset($decoded['sig'], $decoded['method'], $decoded['params'], $decoded['id'])) {
            throw new InvalidSignedActionException;
        }

        // Build the same payload structure used during signing
        $payloadData = [
            'id' => $decoded['id'],
            'method' => $decoded['method'],
            'params' => $decoded['params'],
        ];

        // Include expiry in HMAC if it was part of the signed payload
        if (isset($decoded['exp'])) {
            $payloadData['exp'] = $decoded['exp'];
        }

        $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $expectedSig = hash_hmac('sha256', $payload, config('app.key'));

        if (! hash_equals($expectedSig, $decoded['sig'])) {
            throw new InvalidSignedActionException($decoded['method']);
        }

        // Verify payload has not expired
        if (isset($decoded['exp']) && Carbon::now()->timestamp > $decoded['exp']) {
            throw new ExpiredSignedActionException($decoded['method']);
        }

        // Verify component ID matches
        if ($decoded['id'] !== $this->component->getId()) {
            throw new InvalidSignedActionException($decoded['method']);
        }

        // Verify target method requires signing
        if (! $this->methodIsSigned($decoded['method'])) {
            throw new InvalidSignedActionException($decoded['method']);
        }

        return $decoded;
    }

    /**
     * Generate a signed payload string for use in testing or programmatic calls.
     */
    public static function generateSignedPayload(string $componentId, string $method, mixed ...$params): string
    {
        return static::generateSignedPayloadWithTtl(static::$ttl, $componentId, $method, ...$params);
    }

    /**
     * Generate a signed payload with an explicit TTL.
     */
    public static function generateSignedPayloadWithTtl(?int $ttl, string $componentId, string $method, mixed ...$params): string
    {
        $ttl = static::normalizeTtl($ttl);

        $payloadData = [
            'id' => $componentId,
            'method' => $method,
            'params' => $params,
        ];

        if ($ttl !== null) {
            $payloadData['exp'] = Carbon::now()->timestamp + $ttl;
        }

        $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $signature = hash_hmac('sha256', $payload, config('app.key'));

        return base64_encode(json_encode(array_merge($payloadData, [
            'sig' => $signature,
        ])));
    }

    /**
     * Generate a signed action string for use in Blade templates.
     * Used by the @livewireAction Blade directive.
     */
    public static function generateSignedAction(string $componentId, string $method, mixed ...$params): string
    {
        $payload = self::generateSignedPayload($componentId, $method, ...$params);

        return "__callSigned('{$payload}')";
    }

    /**
     * Generate a signed action string with per-method TTL resolution.
     * Used internally when the component instance is available.
     */
    public static function generateSignedActionForComponent(object $component, string $method, mixed ...$params): string
    {
        $ttl = static::getMethodTtl($component, $method);
        $payload = static::generateSignedPayloadWithTtl($ttl, $component->getId(), $method, ...$params);

        return "__callSigned('{$payload}')";
    }
}
