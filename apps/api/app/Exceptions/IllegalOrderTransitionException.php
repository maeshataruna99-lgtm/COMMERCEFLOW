<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;

final class IllegalOrderTransitionException extends \DomainException
{
    public function __construct(
        public readonly OrderStatus $current,
        public readonly OrderStatus $target,
        string $message = '',
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : sprintf('Illegal order transition %s -> %s.', $current->value, $target->value),
        );
    }
}
