<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\ReservationState;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\OrderLifecycleService;
use App\Services\OrderTransitions;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCancellationRefundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Order, 1: Inventory}
     */
    private function makeReservedOrder(int $qty = 3, int $physical = 10): array
    {
        $user = User::factory()->create();
        $product = Product::create([
            'sku' => 'SKU-'.Str::uuid()->toString(),
            'name' => 'Widget',
            'price_cents' => 1000,
            'status' => 'active',
        ]);
        $inventory = Inventory::create([
            'product_id' => $product->id,
            'physical_stock' => $physical,
            'reserved_stock' => 0,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::uuid()->toString(),
            'status' => OrderStatus::RESERVED->value,
            'total_cents' => 0,
        ]);

        app(StockReservationService::class)->reserve($inventory, $qty, $order, now()->addMinutes(30));

        return [$order, $inventory];
    }

    /**
     * @return array{0: Order, 1: Inventory}
     */
    private function makePaidOrder(int $qty = 3, int $physical = 10): array
    {
        [$order, $inventory] = $this->makeReservedOrder($qty, $physical);

        app(StockReservationService::class)->consume($order);
        OrderTransitions::advance($order, OrderStatus::PAID);

        return [$order, $inventory];
    }

    public function test_reserved_order_cancellation_releases_reservations(): void
    {
        [$order, $inventory] = $this->makeReservedOrder();

        $order = app(OrderLifecycleService::class)->cancel($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);

        $reservation = StockReservation::where('order_id', $order->id)->first();
        $this->assertSame(ReservationState::RELEASED, $reservation->fresh()->state);

        $this->assertSame(10, $inventory->fresh()->physical_stock, 'Cancel must not change physical stock.');
        $this->assertSame(0, $inventory->fresh()->reserved_stock, 'Cancel must decrement reserved stock back to 0.');

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $inventory->id,
            'type' => InventoryMovementType::RELEASE->value,
            'quantity' => 3,
            'before_physical' => 10,
            'after_physical' => 10,
            'before_reserved' => 3,
            'after_reserved' => 0,
        ]);
    }

    public function test_created_order_cancellation_transitions_directly_without_movements(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::uuid()->toString(),
            'status' => OrderStatus::CREATED->value,
            'total_cents' => 0,
        ]);

        $order = app(OrderLifecycleService::class)->cancel($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_paid_order_refund_restores_physical_stock_via_return(): void
    {
        [$order, $inventory] = $this->makePaidOrder();

        $this->assertSame(7, $inventory->fresh()->physical_stock, 'Precondition: consumption shipped 3 units out.');
        $this->assertSame(0, $inventory->fresh()->reserved_stock);

        $order = app(OrderLifecycleService::class)->refund($order);

        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->assertSame(10, $inventory->fresh()->physical_stock, 'Refund must restore physical via RETURN.');

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $inventory->id,
            'type' => InventoryMovementType::RETURN->value,
            'quantity' => 3,
            'before_physical' => 7,
            'after_physical' => 10,
        ]);
    }

    public function test_shipped_order_refund_restores_physical_stock_exactly_once(): void
    {
        [$order, $inventory] = $this->makePaidOrder();

        OrderTransitions::advance($order, OrderStatus::PACKED);
        OrderTransitions::advance($order, OrderStatus::SHIPPED);

        app(OrderLifecycleService::class)->refund($order);

        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->assertSame(10, $inventory->fresh()->physical_stock);
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('inventory_id', $inventory->id)
                ->where('type', InventoryMovementType::RETURN->value)
                ->count(),
            'Exactly one RETURN movement must be written for a SHIPPED refund.',
        );
    }

    public function test_completed_order_refund_restores_physical_stock_exactly_once(): void
    {
        [$order, $inventory] = $this->makePaidOrder();

        OrderTransitions::advance($order, OrderStatus::PACKED);
        OrderTransitions::advance($order, OrderStatus::SHIPPED);
        OrderTransitions::advance($order, OrderStatus::COMPLETED);

        app(OrderLifecycleService::class)->refund($order);

        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->assertSame(10, $inventory->fresh()->physical_stock);
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('inventory_id', $inventory->id)
                ->where('type', InventoryMovementType::RETURN->value)
                ->count(),
            'Exactly one RETURN movement must be written for a COMPLETED refund.',
        );
    }

    public function test_release_runs_exactly_once_when_cancelling_twice(): void
    {
        [$order, $inventory] = $this->makeReservedOrder();

        $service = app(OrderLifecycleService::class);
        $service->cancel($order);
        $service->cancel($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(10, $inventory->fresh()->physical_stock);
        $this->assertSame(0, $inventory->fresh()->reserved_stock);
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('inventory_id', $inventory->id)
                ->where('type', InventoryMovementType::RELEASE->value)
                ->count(),
            'A second cancellation must not write a duplicate RELEASE movement.',
        );
    }

    public function test_refund_is_idempotent_when_attempted_twice(): void
    {
        [$order, $inventory] = $this->makePaidOrder();

        $service = app(OrderLifecycleService::class);
        $service->refund($order);
        $service->refund($order);

        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->assertSame(10, $inventory->fresh()->physical_stock);
        $this->assertSame(
            1,
            DB::table('stock_movements')
                ->where('inventory_id', $inventory->id)
                ->where('type', InventoryMovementType::RETURN->value)
                ->count(),
            'A second refund must not write a duplicate RETURN movement.',
        );
    }
}
