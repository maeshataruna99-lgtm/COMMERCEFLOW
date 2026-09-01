<?php

namespace App\Enums;

enum ReservationState: string
{
    case ACTIVE = 'ACTIVE';
    case EXPIRED = 'EXPIRED';
    case RELEASED = 'RELEASED';
    case CONSUMED = 'CONSUMED';
}
