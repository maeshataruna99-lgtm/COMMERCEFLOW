<?php

namespace App\Exceptions;

final class InsufficientStockException extends \RuntimeException
{
    public const CODE = 'INSUFFICIENT_STOCK';

    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 409, $previous);
    }

    public function statusCode(): int
    {
        return 409;
    }
}
