<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\ReservationState;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\ReservationExpiryService;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationExpiryTest extends TestCase
{
    use RefreshDatabase;

    private ReservationExpiryService $expiry;

    private StockReservationService $reservationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expiry = app(ReservationExpiryService::class);
        $this->reservationService = app(StockReservationService::class);
    }

    public function test_expired_reservation_releases_reserved_stock_and_transitions_order(): void
    {
        $inventory = $this->makeInventory(10);
        $order = $this->makeReservedOrder();
        $this->reservationService->reserve($inventory, 7, $order, now()->subMinutes(5));
        $this->expireReservations($order);

        $this->expiry->expireAll();

        $row = $inventory->fresh();
        $this->assertSame(10, (int) $row->physical_stock, 'physical_stock must be unchanged by expiry.');
        $this->assertSame(0, (int) $row->reserved_stock, 'reserved_stock must drop by 7 (10 -> 3 then -> 0).');

        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'state' => ReservationState::EXPIRED->value,
        ]);

        $this->assertSame(OrderStatus::EXPIRED, $order->fresh()->status);

        $this->assertSame(
            1,
            StockMovement::query()
                ->where('inventory_id', $inventory->id)
                ->where('type', InventoryMovementType::RELEASE->value)
                ->count(),
            'Exactly one RELEASE movement must be written.',
        );
    }

    public function test_multi_line_order_expires_atomically(): void
    {
        $inventoryA = $this->makeInventory(10);
        $inventoryB = $this->makeInventory(20);
        $order = $this->makeReservedOrder();

        $this->reservationService->reserve($inventoryA, 4, $order, now()->subMinutes(5));
        $this->reservationService->reserve($inventoryB, 6, $order, now()->subMinutes(5));
        $this->expireReservations($order);

        $this->expiry->expireAll();

        $this->assertSame(OrderStatus::EXPIRED, $order->fresh()->status);
        $this->assertSame(0, (int) $inventoryA->fresh()->reserved_stock);
        $this->assertSame(0, (int) $inventoryB->fresh()->reserved_stock);
        $this->assertSame(10, (int) $inventoryA->fresh()->physical_stock);
        $this->assertSame(20, (int) $inventoryB->fresh()->physical_stock);

        $this->assertSame(
            2,
            StockReservation::query()
                ->where('order_id', $order->id)
                ->where('state', ReservationState::EXPIRED->value)
                ->count(),
            'Both reservations must be EXPIRED in one pass.',
        );
        $this->assertSame(
            2,
            StockMovement::query()
                ->where('type', InventoryMovementType::RELEASE->value)
                ->whereIn('inventory_id', [$inventoryA->id, $inventoryB->id])
                ->count(),
            'One RELEASE movement per reservation (2 total).',
        );
    }

    public function test_expiry_is_idempotent_across_double_runs(): void
    {
        $inventory = $this->makeInventory(10);
        $order = $this->makeReservedOrder();
        $this->reservationService->reserve($inventory, 5, $order, now()->subMinutes(5));
        $this->expireReservations($order);

        $this->expiry->expireAll();
        $this->expiry->expireAll();

        $this->assertSame(0, (int) $inventory->fresh()->reserved_stock);
        $this->assertSame(1, $this->releaseMovementCount($inventory->id));
        $this->assertSame(OrderStatus::EXPIRED, $order->fresh()->status);
    }

    public function test_one_raced_order_does_not_abort_the_rest_of_the_batch(): void
    {
        $inventoryGood = $this->makeInventory(10);
        $inventoryRaced = $this->makeInventory(10);

        $goodOrder = $this->makeReservedOrder();
        $this->reservationService->reserve($inventoryGood, 3, $goodOrder, now()->subMinutes(5));
        $this->expireReservations($goodOrder);

        // A raced order that has already been paid: it must not prevent the
        // other (expired-eligible) order in the same batch from expiring.
        $racedOrder = $this->makeReservedOrder();
        $this->reservationService->reserve($inventoryRaced, 3, $racedOrder, now()->subMinutes(5));
        $racedOrder->update(['status' => OrderStatus::PAID->value]);

        $this->expiry->expireAll();

        $this->assertSame(OrderStatus::EXPIRED, $goodOrder->fresh()->status);
        $this->assertSame(0, (int) $inventoryGood->fresh()->reserved_stock);
        $this->assertSame(
            1,
            $this->releaseMovementCount($inventoryGood->id),
            'The good order must still be expired despite the raced order in the batch.',
        );

        // The raced (PAID) order must NOT be demoted back to EXPIRED, and its
        // remaining ACTIVE reservations must still be expired.
        $this->assertSame(
            OrderStatus::PAID,
            $racedOrder->fresh()->status,
            'A concurrently-paid order must never be demoted to EXPIRED.',
        );
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $racedOrder->id,
            'inventory_id' => $inventoryRaced->id,
            'state' => ReservationState::EXPIRED->value,
        ]);
        $this->assertSame(0, (int) $inventoryRaced->fresh()->reserved_stock);
        $this->assertSame(
            10,
            (int) $inventoryRaced->fresh()->physical_stock,
            'Physical stock must be unchanged, restoring available to full.',
        );
    }

    public function test_paid_order_that_races_with_expiry_is_not_demoted_and_reservations_expired(): void
    {
        $inventory = $this->makeInventory(10);
        $order = $this->makeReservedOrder();
        $this->reservationService->reserve($inventory, 7, $order, now()->subMinutes(5));

        // Simulate the race: a payment webhook commits the order to PAID while
        // its ACTIVE reservations are still past-due (the webhook consumed none
        // of them in this scenario). The expiry job must lock the order, see
        // PAID, expire the remaining ACTIVE reservations, and NOT write EXPIRED
        // over the committed PAID status.
        $order->update(['status' => OrderStatus::PAID->value]);

        $this->expiry->expireAll();

        $this->assertSame(
            OrderStatus::PAID,
            $order->fresh()->status,
            'A paid order must not be demoted to EXPIRED by the expiry job.',
        );

        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'state' => ReservationState::EXPIRED->value,
        ]);

        $row = $inventory->fresh();
        $this->assertSame(
            0,
            (int) $row->reserved_stock,
            'All ACTIVE reservations must still be expired regardless of the order status.',
        );
        $this->assertSame(
            10,
            (int) $row->physical_stock,
            'Physical stock unchanged so available returns to full (10).',
        );
        $this->assertSame(
            1,
            $this->releaseMovementCount($inventory->id),
            'Exactly one RELEASE movement must be written for the expired reservation.',
        );
    }

    private function makeInventory(int $physical): Inventory
    {
        $product = Product::create([
            'sku' => 'EXP-'.Str::uuid()->toString(),
            'name' => 'Expiry Widget',
            'price_cents' => 1000,
            'status' => 'active',
        ]);

        return Inventory::create([
            'product_id' => $product->id,
            'physical_stock' => $physical,
            'reserved_stock' => 0,
        ]);
    }

    private function makeReservedOrder(): Order
    {
        $user = User::factory()->create();

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'EXP-'.Str::uuid()->toString(),
            'status' => OrderStatus::RESERVED->value,
            'total_cents' => 0,
        ]);
    }

    /**
     * Force the order's ACTIVE reservations into the past so the expiry job
     * considers them expired.
     */
    private function expireReservations(Order $order): void
    {
        DB::table('stock_reservations')
            ->where('order_id', $order->id)
            ->where('state', ReservationState::ACTIVE->value)
            ->update(['reserved_until' => now()->subMinutes(5)]);
    }

    private function releaseMovementCount(int $inventoryId): int
    {
        return StockMovement::query()
            ->where('inventory_id', $inventoryId)
            ->where('type', InventoryMovementType::RELEASE->value)
            ->count();
    }
}
