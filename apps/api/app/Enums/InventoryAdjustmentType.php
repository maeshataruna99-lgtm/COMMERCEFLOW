<?php

namespace App\Enums;

enum InventoryAdjustmentType: string
{
    case ADD = 'ADD';
    case REDUCE = 'REDUCE';
}
