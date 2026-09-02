<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\ReservationState;
use App\Exceptions\InvalidStockAdjustmentException;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeInventory(int $physical, int $reserved = 0): Inventory
    {
        $product = Product::create([
            'sku' => 'SKU-'.Str::uuid()->toString(),
            'name' => 'Widget',
            'price_cents' => 1000,
            'status' => 'active',
        ]);

        return Inventory::create([
            'product_id' => $product->id,
            'physical_stock' => $physical,
            'reserved_stock' => $reserved,
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create();

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::uuid()->toString(),
            'status' => 'CREATED',
            'total_cents' => 0,
        ]);
    }

    private function makeReservation(Inventory $inventory, int $qty, Carbon $until): StockReservation
    {
        $order = $this->makeOrder();

        return StockReservation::create([
            'order_id' => $order->id,
            'product_id' => $inventory->product_id,
            'inventory_id' => $inventory->id,
            'quantity' => $qty,
            'state' => ReservationState::ACTIVE->value,
            'reserved_until' => $until,
        ]);
    }

    public function test_downward_adjustment_releases_unsupported_reservations_then_applies_movement(): void
    {
        $inventory = $this->makeInventory(5, 5);

        // Two ACTIVE reservations totalling reserved = 5; the earlier-expiring
        // one (qty 3) must be released first to satisfy reserved <= 3.
        $later = $this->makeReservation($inventory, 2, Carbon::now()->addDays(2));
        $earlier = $this->makeReservation($inventory, 3, Carbon::now()->addDays(1));

        $this->assertSame(5, $inventory->fresh()->reserved_stock);

        // Downward adjustment: physical 5 -> 3 (delta -2).
        app(InventoryAdjustmentService::class)->adjust($inventory, -2, null);

        $fresh = $inventory->fresh();
        $this->assertSame(3, $fresh->physical_stock);
        $this->assertLessThanOrEqual($fresh->physical_stock, $fresh->reserved_stock);
        $this->assertSame(2, $fresh->reserved_stock);

        // The earlier-expiring reservation (qty 3) is released first.
        $this->assertSame(ReservationState::RELEASED, $earlier->fresh()->state);
        $this->assertSame(ReservationState::ACTIVE, $later->fresh()->state);

        // A RELEASE movement was recorded for the released reservation.
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $inventory->id,
            'type' => InventoryMovementType::RELEASE->value,
            'reservation_id' => $earlier->id,
            'quantity' => 3,
        ]);

        // The ADJUSTMENT movement reflects physical 5 -> 3.
        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $inventory->id,
            'type' => InventoryMovementType::ADJUSTMENT->value,
            'quantity' => 2,
            'before_physical' => 5,
            'after_physical' => 3,
        ]);

        // The audit trail captures the pre- and post-adjustment inventory state.
        $audit = AuditLog::where('entity_id', $inventory->id)->firstOrFail();
        $this->assertSame(['physical_stock' => 5, 'reserved_stock' => 5], $audit->before);
        $this->assertSame(['physical_stock' => 3, 'reserved_stock' => 2], $audit->after);
    }

    public function test_overshooting_downward_adjustment_is_rejected_and_state_unchanged(): void
    {
        $inventory = $this->makeInventory(2, 0);

        try {
            app(InventoryAdjustmentService::class)->adjust($inventory, -10, null);
            $this->fail('Expected InvalidStockAdjustmentException.');
        } catch (InvalidStockAdjustmentException $e) {
            $this->assertSame(InvalidStockAdjustmentException::CODE, $e::CODE);
        }

        $fresh = $inventory->fresh();
        $this->assertSame(2, $fresh->physical_stock);
        $this->assertSame(0, $fresh->reserved_stock);

        // No ADJUSTMENT movement nor audit row should have been written.
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_adjustment_is_audited_with_before_after_actor_and_timestamp(): void
    {
        $user = User::factory()->create();
        $inventory = $this->makeInventory(10, 2);

        app(InventoryAdjustmentService::class)->adjust($inventory, 3, $user->id);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'inventory.adjustment',
            'entity_type' => 'inventory',
            'entity_id' => $inventory->id,
        ]);

        $audit = AuditLog::where('entity_id', $inventory->id)->firstOrFail();
        $this->assertSame(10, $audit->before['physical_stock']);
        $this->assertSame(13, $audit->after['physical_stock']);
        $this->assertNotNull($audit->created_at);
    }
}
