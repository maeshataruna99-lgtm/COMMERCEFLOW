<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';
    case REFUNDED = 'REFUNDED';

    /**
     * The explicit forward-transition matrix (spec §6.4 / F2.20).
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::PENDING => [self::PAID, self::FAILED, self::EXPIRED],
            self::FAILED => [self::PAID, self::FAILED],
            self::PAID => [self::REFUNDED],
            self::EXPIRED, self::REFUNDED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStates(), true);
    }
}
