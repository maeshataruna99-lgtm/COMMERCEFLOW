<?php

namespace App\Enums;

enum ShipmentStatus: string
{
    case CREATED = 'CREATED';
    case PACKED = 'PACKED';
    case SHIPPED = 'SHIPPED';
    case DELIVERED = 'DELIVERED';

    /**
     * The explicit forward-transition matrix for a shipment.
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::CREATED => [self::PACKED],
            self::PACKED => [self::SHIPPED],
            self::SHIPPED => [self::DELIVERED],
            self::DELIVERED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStates(), true);
    }
}
