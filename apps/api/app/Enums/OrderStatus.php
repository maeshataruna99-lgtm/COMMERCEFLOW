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
}
