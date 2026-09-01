<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case PURCHASE = 'PURCHASE';
    case SALE = 'SALE';
    case RESERVATION = 'RESERVATION';
    case RELEASE = 'RELEASE';
    case ADJUSTMENT = 'ADJUSTMENT';
    case RETURN = 'RETURN';
}
