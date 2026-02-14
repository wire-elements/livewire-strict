<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions;

class ExpiredSignedActionException extends \Exception
{
    public function __construct(string $method = '')
    {
        parent::__construct(
            $method
                ? "Signed action [{$method}] has expired."
                : 'Signed action has expired.'
        );
    }
}
