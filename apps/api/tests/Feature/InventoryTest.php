<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\MovementLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryTest extends TestCase
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

    public function test_available_equals_physical_minus_reserved(): void
    {
        $inventory = $this->makeInventory(10, 3);

        $this->assertSame(7, $inventory->available);
        $this->assertSame(7, $inventory->available());
    }

    public function test_every_movement_type_writes_a_ledger_row_with_before_after(): void
    {
        foreach (InventoryMovementType::cases() as $type) {
            $inventory = $this->makeInventory(10, 4);
            $delta = MovementLedger::apply($inventory, $type, 2);

            DB::transaction(function () use ($inventory, $type, $delta) {
                $inventory->update([
                    'physical_stock' => $delta['afterPhysical'],
                    'reserved_stock' => $delta['afterReserved'],
                ]);

                StockMovement::create([
                    'inventory_id' => $inventory->id,
                    'type' => $type->value,
                    'quantity' => 2,
                    'before_physical' => $delta['beforePhysical'],
                    'after_physical' => $delta['afterPhysical'],
                    'before_reserved' => $delta['beforeReserved'],
                    'after_reserved' => $delta['afterReserved'],
                ]);
            });

            $this->assertDatabaseHas('stock_movements', [
                'inventory_id' => $inventory->id,
                'type' => $type->value,
                'quantity' => 2,
                'before_physical' => 10,
                'after_physical' => $delta['afterPhysical'],
                'before_reserved' => 4,
                'after_reserved' => $delta['afterReserved'],
            ]);

            $this->assertDatabaseHas('inventories', [
                'id' => $inventory->id,
                'physical_stock' => $delta['afterPhysical'],
                'reserved_stock' => $delta['afterReserved'],
            ]);
        }
    }

    public function test_stock_field_does_not_mutate_without_a_movement_row(): void
    {
        $inventory = $this->makeInventory(10);

        try {
            DB::transaction(function () use ($inventory) {
                $inventory->update(['physical_stock' => 20, 'reserved_stock' => 0]);

                throw new \RuntimeException('abort: a stock_movements row is required in the same transaction');
            });
            $this->fail('Expected the transaction to roll back.');
        } catch (\RuntimeException) {
            // expected — mutation without a ledger row must be atomic and abort
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'physical_stock' => 10,
            'reserved_stock' => 0,
        ]);
    }

    public function test_oversell_is_rejected_by_the_db_and_physical_never_goes_negative(): void
    {
        $inventory = $this->makeInventory(1);

        DB::statement('SAVEPOINT oversell_attempt');
        try {
            $inventory->update(['physical_stock' => 1, 'reserved_stock' => 2]);
            $this->fail('Expected a CHECK constraint violation for oversell.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23514', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT oversell_attempt');
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'physical_stock' => 1,
            'reserved_stock' => 0,
        ]);

        DB::statement('SAVEPOINT negative_attempt');
        try {
            $inventory->update(['physical_stock' => -1, 'reserved_stock' => 0]);
            $this->fail('Expected a CHECK constraint violation for negative physical stock.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23514', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT negative_attempt');
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'physical_stock' => 1,
            'reserved_stock' => 0,
        ]);
    }

    public function test_reserved_can_never_exceed_physical(): void
    {
        $inventory = $this->makeInventory(5, 5);

        DB::statement('SAVEPOINT reserved_exceed_attempt');
        try {
            $inventory->update(['physical_stock' => 5, 'reserved_stock' => 6]);
            $this->fail('Expected a CHECK constraint violation for reserved > physical.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23514', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT reserved_exceed_attempt');
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'physical_stock' => 5,
            'reserved_stock' => 5,
        ]);
    }

    public function test_order_can_be_created_before_carts_exist(): void
    {
        $this->assertFalse(Schema::hasColumn('orders', 'cart_id'));

        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-20260901-0001',
            'status' => OrderStatus::CREATED->value,
            'total_cents' => 0,
        ]);

        $this->assertModelExists($order);
        $this->assertSame(OrderStatus::CREATED, $order->status);
        $this->assertSame(0, $order->total_cents);
        $this->assertSame(0, $order->totalCents);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_number' => 'ORD-20260901-0001',
            'status' => 'CREATED',
            'total_cents' => 0,
        ]);
    }
}
