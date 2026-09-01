<?php

namespace Tests\Unit;

use App\Enums\InventoryMovementType;
use App\Models\Inventory;
use App\Services\MovementLedger;
use PHPUnit\Framework\TestCase;

class InventoryMovementMathTest extends TestCase
{
    private function inventory(int $physical, int $reserved): Inventory
    {
        return new Inventory([
            'physical_stock' => $physical,
            'reserved_stock' => $reserved,
        ]);
    }

    public function test_purchase_increases_physical_stock_only(): void
    {
        $delta = MovementLedger::apply($this->inventory(10, 0), InventoryMovementType::PURCHASE, 5);

        $this->assertSame(10, $delta['beforePhysical']);
        $this->assertSame(15, $delta['afterPhysical']);
        $this->assertSame(0, $delta['beforeReserved']);
        $this->assertSame(0, $delta['afterReserved']);
    }

    public function test_reservation_increases_reserved_stock_only(): void
    {
        $delta = MovementLedger::apply($this->inventory(10, 2), InventoryMovementType::RESERVATION, 3);

        $this->assertSame(10, $delta['beforePhysical']);
        $this->assertSame(10, $delta['afterPhysical']);
        $this->assertSame(2, $delta['beforeReserved']);
        $this->assertSame(5, $delta['afterReserved']);
    }

    public function test_sale_decreases_physical_and_reserved_stock(): void
    {
        $delta = MovementLedger::apply($this->inventory(10, 4), InventoryMovementType::SALE, 4);

        $this->assertSame(10, $delta['beforePhysical']);
        $this->assertSame(6, $delta['afterPhysical']);
        $this->assertSame(4, $delta['beforeReserved']);
        $this->assertSame(0, $delta['afterReserved']);
    }

    public function test_release_decreases_reserved_stock_only(): void
    {
        $delta = MovementLedger::apply($this->inventory(10, 4), InventoryMovementType::RELEASE, 2);

        $this->assertSame(10, $delta['beforePhysical']);
        $this->assertSame(10, $delta['afterPhysical']);
        $this->assertSame(4, $delta['beforeReserved']);
        $this->assertSame(2, $delta['afterReserved']);
    }

    public function test_return_increases_physical_stock_only(): void
    {
        $delta = MovementLedger::apply($this->inventory(10, 0), InventoryMovementType::RETURN, 2);

        $this->assertSame(10, $delta['beforePhysical']);
        $this->assertSame(12, $delta['afterPhysical']);
        $this->assertSame(0, $delta['beforeReserved']);
        $this->assertSame(0, $delta['afterReserved']);
    }

    public function test_adjustment_applies_signed_delta_to_physical_stock(): void
    {
        $up = MovementLedger::apply($this->inventory(10, 0), InventoryMovementType::ADJUSTMENT, 3);
        $down = MovementLedger::apply($this->inventory(10, 0), InventoryMovementType::ADJUSTMENT, -2);

        $this->assertSame(13, $up['afterPhysical']);
        $this->assertSame(8, $down['afterPhysical']);
        $this->assertSame(0, $up['afterReserved']);
        $this->assertSame(0, $down['afterReserved']);
    }

    public function test_available_is_physical_minus_reserved(): void
    {
        $this->assertSame(7, MovementLedger::available(10, 3));
        $this->assertSame(10, MovementLedger::available(10, 0));
        $this->assertSame(0, MovementLedger::available(5, 5));
    }

    public function test_apply_is_pure_and_does_not_mutate_the_inventory(): void
    {
        $inventory = $this->inventory(10, 0);

        MovementLedger::apply($inventory, InventoryMovementType::PURCHASE, 5);

        $this->assertSame(10, $inventory->physical_stock);
        $this->assertSame(0, $inventory->reserved_stock);
    }
}
