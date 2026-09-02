<?php

namespace App\Enums;

enum OrderStatus: string
{
    case CREATED = 'CREATED';
    case RESERVED = 'RESERVED';
    case PAID = 'PAID';
    case PACKED = 'PACKED';
    case SHIPPED = 'SHIPPED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case REFUNDED = 'REFUNDED';

    /**
     * The explicit forward-transition matrix (spec §6.4 lifecycle).
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::CREATED => [self::RESERVED, self::CANCELLED],
            self::RESERVED => [self::PAID, self::CANCELLED, self::EXPIRED],
            self::PAID => [self::PACKED, self::REFUNDED],
            self::PACKED => [self::SHIPPED, self::REFUNDED],
            self::SHIPPED => [self::COMPLETED, self::REFUNDED],
            self::COMPLETED => [self::REFUNDED],
            self::CANCELLED, self::EXPIRED, self::REFUNDED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStates(), true);
    }
}
