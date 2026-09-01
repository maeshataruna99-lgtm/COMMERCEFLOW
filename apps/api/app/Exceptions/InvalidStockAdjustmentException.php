<?php

namespace App\Exceptions;

final class InvalidStockAdjustmentException extends \RuntimeException
{
    public const CODE = 'INVALID_ADJUSTMENT';

    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 422, $previous);
    }

    public function statusCode(): int
    {
        return 422;
    }
}
