<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;

class MovementLedger
{
    public static function available(int $physicalStock, int $reservedStock): int
    {
        return $physicalStock - $reservedStock;
    }

    /**
     * Compute the before/after physical and reserved stock values a movement
     * would produce. Pure function: it never mutates the inventory.
     *
     * @return array{beforePhysical: int, afterPhysical: int, beforeReserved: int, afterReserved: int}
     */
    public static function apply(Inventory $inventory, InventoryMovementType $type, int $qty): array
    {
        $beforePhysical = (int) $inventory->physical_stock;
        $beforeReserved = (int) $inventory->reserved_stock;

        $afterPhysical = $beforePhysical;
        $afterReserved = $beforeReserved;

        switch ($type) {
            case InventoryMovementType::PURCHASE:
                $afterPhysical += $qty;
                break;
            case InventoryMovementType::SALE:
                $afterReserved -= $qty;
                $afterPhysical -= $qty;
                break;
            case InventoryMovementType::RESERVATION:
                $afterReserved += $qty;
                break;
            case InventoryMovementType::RELEASE:
                $afterReserved -= $qty;
                break;
            case InventoryMovementType::RETURN:
                $afterPhysical += $qty;
                break;
            case InventoryMovementType::ADJUSTMENT:
                $afterPhysical += $qty;
                break;
        }

        return [
            'beforePhysical' => $beforePhysical,
            'afterPhysical' => $afterPhysical,
            'beforeReserved' => $beforeReserved,
            'afterReserved' => $afterReserved,
        ];
    }
}
