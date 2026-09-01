<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentWebhookResult;
use App\Enums\ReservationState;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderTransitions;
use App\Services\PaymentWebhookService;
use App\Services\StockReservationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(int $priceCents = 10000): Product
    {
        return Product::create([
            'sku' => 'SKU-'.Str::uuid()->toString(),
            'name' => 'Widget',
            'price_cents' => $priceCents,
            'status' => 'active',
        ]);
    }

    private function makeInventory(Product $product, int $physical = 10): Inventory
    {
        return Inventory::create([
            'product_id' => $product->id,
            'physical_stock' => $physical,
            'reserved_stock' => 0,
        ]);
    }

    /**
     * @return array{0: Order, 1: Inventory}
     */
    private function makeCheckoutOrder(int $priceCents = 10000, int $qty = 1, int $physical = 10): array
    {
        $user = User::factory()->create();
        $product = $this->makeProduct($priceCents);
        $inventory = $this->makeInventory($product, $physical);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => CartStatus::ACTIVE->value,
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price_cents' => $priceCents,
        ]);

        $order = app(CheckoutService::class)->checkout($cart);

        return [$order, $inventory];
    }

    private function webhook(): PaymentWebhookService
    {
        return app(PaymentWebhookService::class);
    }

    public function test_duplicate_webhook_key_is_processed_exactly_once(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();

        $first = $this->webhook()->handle($order, 'evt-1', 10000, 'prov-ref-1');
        $this->assertSame(PaymentWebhookResult::PROCESSED, $first);

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => PaymentStatus::PAID->value,
        ]);
        $paymentId = Payment::where('order_id', $order->id)->first()->id;
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $paymentId,
            'idempotency_key' => 'evt-1',
            'status' => PaymentTransactionStatus::SUCCEEDED->value,
        ]);
        $this->assertSame(9, $inventory->fresh()->physical_stock);
        $this->assertSame(0, $inventory->fresh()->reserved_stock);
        $this->assertSame(
            1,
            DB::table('stock_movements')->where('type', InventoryMovementType::SALE->value)->count(),
        );

        $second = $this->webhook()->handle($order, 'evt-1', 10000, 'prov-ref-1');
        $this->assertSame(PaymentWebhookResult::ALREADY_HANDLED, $second);

        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(9, $inventory->fresh()->physical_stock, 'A duplicate must not re-consume stock.');
        $this->assertSame(0, $inventory->fresh()->reserved_stock);
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame(
            1,
            DB::table('stock_movements')->where('type', InventoryMovementType::SALE->value)->count(),
            'A duplicate must not write a second SALE movement.',
        );
    }

    public function test_wrong_amount_webhook_is_rejected_without_state_changes(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();

        $result = $this->webhook()->handle($order, 'evt-2', 1000, 'prov-ref-2');
        $this->assertSame(PaymentWebhookResult::REJECTED, $result);

        $this->assertSame(OrderStatus::RESERVED, $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => PaymentStatus::PENDING->value,
        ]);
        $paymentId = Payment::where('order_id', $order->id)->first()->id;
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $paymentId,
            'idempotency_key' => 'evt-2',
            'status' => PaymentTransactionStatus::REJECTED->value,
        ]);
        $this->assertSame(10, $inventory->fresh()->physical_stock);
        $this->assertSame(1, $inventory->fresh()->reserved_stock);
        $this->assertSame(
            1,
            DB::table('stock_movements')->where('type', InventoryMovementType::RESERVATION->value)->count(),
        );
        $this->assertSame(
            0,
            DB::table('stock_movements')->where('type', InventoryMovementType::SALE->value)->count(),
            'A rejected amount mismatch must not consume reservations.',
        );
        $this->assertSame(ReservationState::ACTIVE, $order->stockReservations()->first()->fresh()->state);
    }

    public function test_rejected_webhook_is_recorded_and_redelivery_of_same_key_is_skipped(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();

        app(StockReservationService::class)->expire($order);
        OrderTransitions::advance($order, OrderStatus::EXPIRED);

        $this->assertSame(OrderStatus::EXPIRED, $order->fresh()->status);
        $this->assertSame(0, $inventory->fresh()->reserved_stock, 'Expiry must release reserved stock first.');

        $first = $this->webhook()->handle($order, 'evt-3', 10000, 'prov-ref-3');
        $this->assertSame(PaymentWebhookResult::REJECTED, $first);

        $paymentId = Payment::where('order_id', $order->id)->first()->id;
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $paymentId,
            'idempotency_key' => 'evt-3',
            'status' => PaymentTransactionStatus::REJECTED->value,
        ]);
        $this->assertSame(OrderStatus::EXPIRED, $order->fresh()->status, 'A rejected webhook must not move the order.');
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => PaymentStatus::PENDING->value,
        ]);
        $this->assertSame(
            0,
            DB::table('stock_movements')->where('type', InventoryMovementType::SALE->value)->count(),
            'A rejected webhook must not write a SALE movement.',
        );

        $second = $this->webhook()->handle($order, 'evt-3', 10000, 'prov-ref-3');
        $this->assertSame(PaymentWebhookResult::ALREADY_HANDLED, $second);

        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame(OrderStatus::EXPIRED, $order->fresh()->status);
    }

    public function test_webhook_for_already_paid_order_with_different_key_does_not_double_apply(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();

        $first = $this->webhook()->handle($order, 'evt-1', 10000, 'prov-ref-1');
        $this->assertSame(PaymentWebhookResult::PROCESSED, $first);

        $second = $this->webhook()->handle($order, 'evt-4', 10000, 'prov-ref-4');
        $this->assertSame(PaymentWebhookResult::ALREADY_HANDLED, $second);

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => PaymentStatus::PAID->value,
        ]);
        $this->assertDatabaseCount('payment_transactions', 2);
        $this->assertSame(9, $inventory->fresh()->physical_stock, 'A late successful webhook must not double-apply.');
        $this->assertSame(0, $inventory->fresh()->reserved_stock);
        $this->assertSame(
            1,
            DB::table('stock_movements')->where('type', InventoryMovementType::SALE->value)->count(),
        );
    }

    public function test_provider_failure_webhook_is_recorded_without_state_changes(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();

        $result = $this->webhook()->handle($order, 'evt-5', 10000, 'prov-ref-5', [], 'DECLINED');
        $this->assertSame(PaymentWebhookResult::FAILED, $result);

        $this->assertSame(OrderStatus::RESERVED, $order->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => PaymentStatus::PENDING->value,
        ]);
        $paymentId = Payment::where('order_id', $order->id)->first()->id;
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $paymentId,
            'idempotency_key' => 'evt-5',
            'status' => PaymentTransactionStatus::FAILED->value,
        ]);
        $this->assertSame(1, $inventory->fresh()->reserved_stock, 'A failed payment must keep the reservation ACTIVE.');
        $this->assertSame(ReservationState::ACTIVE, $order->stockReservations()->first()->fresh()->state);
    }

    public function test_payment_status_follows_explicit_transition_set(): void
    {
        $this->assertTrue(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::PAID));
        $this->assertTrue(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::FAILED));
        $this->assertTrue(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::EXPIRED));
        $this->assertFalse(PaymentStatus::PENDING->canTransitionTo(PaymentStatus::REFUNDED));
        $this->assertTrue(PaymentStatus::FAILED->canTransitionTo(PaymentStatus::PAID));
        $this->assertTrue(PaymentStatus::FAILED->canTransitionTo(PaymentStatus::FAILED));
        $this->assertTrue(PaymentStatus::PAID->canTransitionTo(PaymentStatus::REFUNDED));
        $this->assertFalse(PaymentStatus::PAID->canTransitionTo(PaymentStatus::PENDING));
        $this->assertFalse(PaymentStatus::EXPIRED->canTransitionTo(PaymentStatus::PAID));
        $this->assertFalse(PaymentStatus::REFUNDED->canTransitionTo(PaymentStatus::PAID));
    }

    public function test_a_payment_can_be_created_for_an_order_only_once(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount_cents' => 10000,
            'status' => PaymentStatus::PENDING->value,
        ]);

        DB::statement('SAVEPOINT second_payment_attempt');
        try {
            Payment::create([
                'order_id' => $order->id,
                'amount_cents' => 10000,
                'status' => PaymentStatus::PENDING->value,
            ]);
            $this->fail('Expected a UNIQUE constraint violation for a second payment on the same order.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23505', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT second_payment_attempt');
        }

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_transactions_idempotency_key_is_unique(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();
        $paymentId = Payment::where('order_id', $order->id)->first()->id;

        PaymentTransaction::create([
            'payment_id' => $paymentId,
            'idempotency_key' => 'K',
            'status' => PaymentTransactionStatus::PENDING->value,
            'amount_cents' => 10000,
        ]);

        DB::statement('SAVEPOINT duplicate_key_attempt');
        try {
            PaymentTransaction::create([
                'payment_id' => $paymentId,
                'idempotency_key' => 'K',
                'status' => PaymentTransactionStatus::PENDING->value,
                'amount_cents' => 10000,
            ]);
            $this->fail('Expected a UNIQUE constraint violation for a duplicate idempotency_key.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23505', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT duplicate_key_attempt');
        }

        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_payment_with_negative_amount_is_rejected_by_check_constraint(): void
    {
        [$order, $inventory] = $this->makeCheckoutOrder();

        DB::statement('SAVEPOINT negative_amount_attempt');
        try {
            DB::table('payments')->update(['amount_cents' => -1]);
            $this->fail('Expected a CHECK constraint violation for a negative amount.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23514', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT negative_amount_attempt');
        }

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount_cents' => 10000]);
    }
}
