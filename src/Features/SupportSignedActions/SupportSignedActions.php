<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions;

use Livewire\ComponentHook;
use Livewire\Features\SupportAttributes\AttributeLevel;
use WireElements\LivewireStrict\Attributes\Signed;
use WireElements\LivewireStrict\Features\Concerns\MatchesComponents;
use WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions\InvalidSignedActionException;

class SupportSignedActions extends ComponentHook
{
    use MatchesComponents;

    public static bool $enabled = false;

    public static array $components = [];

    public static ?int $ttl = null;

    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        if (self::$enabled === false) {
            return;
        }

        if (! $this->checkIsRequired()) {
            return;
        }

        if ($method === '__callSigned') {
            $this->handleSignedCall($params, $returnEarly);

            return;
        }

        if ($this->methodIsSigned($method)) {
            throw new InvalidSignedActionException($method);
        }
    }

    private function handleSignedCall(array $params, callable $returnEarly): void
    {
        throw_if(
            method_exists($this->component, '__callSigned'),
            \LogicException::class,
            'Component ['.$this->component::class.'] defines a __callSigned method, which collides with the internal signed-action hook.'
        );

        throw_unless(
            isset($params[0]) && is_string($params[0]),
            InvalidSignedActionException::class,
            '__callSigned',
        );

        $payload = SignedPayload::verify($params[0], $this->component);

        throw_unless(
            $this->methodIsSigned($payload->method),
            InvalidSignedActionException::class,
            $payload->method
        );

        $returnEarly(
            $this->component->{$payload->method}(...$payload->params)
        );
    }

    private function methodIsSigned(string $method): bool
    {
        return $this->component
            ->getAttributes()
            ->whereInstanceOf(Signed::class)
            ->filter(fn (Signed $attribute) => $attribute->getLevel() === AttributeLevel::METHOD && $attribute->getName() === $method)
            ->isNotEmpty();
    }
}
