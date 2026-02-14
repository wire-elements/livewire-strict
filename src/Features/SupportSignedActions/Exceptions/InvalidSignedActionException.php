<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions;

class InvalidSignedActionException extends \Exception
{
    public function __construct(string $method = '')
    {
        parent::__construct(
            $method
                ? "Cannot call signed action: [{$method}]. The signature is invalid or missing."
                : 'Cannot call signed action. The payload is invalid.'
        );
    }
}
