<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationState;
use App\Exceptions\IllegalOrderTransitionException;
use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderTransitions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(int $priceCents = 1000): Product
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

    private function makeOrder(?User $user = null, ?Cart $cart = null): Order
    {
        $user ??= User::factory()->create();

        return Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::uuid()->toString(),
            'status' => OrderStatus::CREATED->value,
            'total_cents' => 0,
            'cart_id' => $cart?->id,
        ]);
    }

    public function test_invalid_status_value_is_rejected_by_check_constraint(): void
    {
        $user = User::factory()->create();

        DB::statement('SAVEPOINT invalid_status_attempt');
        try {
            DB::table('orders')->insert([
                'user_id' => $user->id,
                'order_number' => 'ORD-'.Str::uuid()->toString(),
                'status' => 'BOGUS',
                'total_cents' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected a CHECK constraint violation for an out-of-set status.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23514', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT invalid_status_attempt');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_follows_forward_transitions_to_completed(): void
    {
        $order = $this->makeOrder();

        OrderTransitions::advance($order, OrderStatus::RESERVED);
        $this->assertSame(OrderStatus::RESERVED, $order->fresh()->status);

        OrderTransitions::advance($order, OrderStatus::PAID);
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);

        OrderTransitions::advance($order, OrderStatus::PACKED);
        $this->assertSame(OrderStatus::PACKED, $order->fresh()->status);

        OrderTransitions::advance($order, OrderStatus::SHIPPED);
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);

        OrderTransitions::advance($order, OrderStatus::COMPLETED);

        $this->assertSame(OrderStatus::COMPLETED, $order->fresh()->status);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::COMPLETED->value,
        ]);
    }

    public function test_illegal_direct_transition_created_to_completed_is_rejected(): void
    {
        $order = $this->makeOrder();

        try {
            OrderTransitions::advance($order, OrderStatus::COMPLETED);
            $this->fail('Expected CREATED -> COMPLETED to be rejected.');
        } catch (IllegalOrderTransitionException $e) {
            $this->assertSame(OrderStatus::CREATED, $e->current);
            $this->assertSame(OrderStatus::COMPLETED, $e->target);
        }

        $this->assertSame(OrderStatus::CREATED, $order->fresh()->status);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::CREATED->value,
        ]);
    }

    public function test_order_total_equals_sum_of_line_totals(): void
    {
        $order = $this->makeOrder();

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->makeProduct(1000)->id,
            'unit_price_cents' => 1000,
            'quantity' => 1,
            'line_total_cents' => 1000,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->makeProduct(1250)->id,
            'unit_price_cents' => 1250,
            'quantity' => 2,
            'line_total_cents' => 2500,
        ]);

        $this->assertSame(3500, $order->fresh()->total_cents);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_cents' => 3500]);
    }

    public function test_order_total_is_zero_for_a_zero_item_manual_order(): void
    {
        $order = $this->makeOrder();

        $reloaded = $order->fresh();

        $this->assertSame(0, $reloaded->total_cents);
        $this->assertNotNull($reloaded->total_cents, 'total_cents must default to 0, not NULL.');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_cents' => 0]);
    }

    public function test_order_total_stays_correct_after_a_line_is_deleted(): void
    {
        $order = $this->makeOrder();

        $keep = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->makeProduct(2500)->id,
            'unit_price_cents' => 2500,
            'quantity' => 1,
            'line_total_cents' => 2500,
        ]);
        $remove = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->makeProduct(1000)->id,
            'unit_price_cents' => 1000,
            'quantity' => 1,
            'line_total_cents' => 1000,
        ]);

        $this->assertSame(3500, $order->fresh()->total_cents);

        $remove->delete();
        $this->assertNotNull($keep->fresh(), 'keep line must still exist.');

        $this->assertSame(2500, $order->fresh()->total_cents);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_cents' => 2500]);
    }

    public function test_order_total_recomputes_when_a_line_is_updated(): void
    {
        $order = $this->makeOrder();

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->makeProduct(1000)->id,
            'unit_price_cents' => 1000,
            'quantity' => 1,
            'line_total_cents' => 1000,
        ]);

        $item->update(['quantity' => 2, 'line_total_cents' => 2000]);

        $this->assertSame(2000, $order->fresh()->total_cents);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total_cents' => 2000]);
    }

    public function test_multiple_manual_orders_with_null_cart_id_are_allowed(): void
    {
        $first = $this->makeOrder();
        $second = $this->makeOrder();

        $this->assertNull($first->cart_id);
        $this->assertNull($second->cart_id);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_cart_can_back_at_most_one_order(): void
    {
        $cart = Cart::create([
            'user_id' => User::factory()->create()->id,
            'status' => CartStatus::ACTIVE->value,
        ]);

        $this->makeOrder(cart: $cart);

        DB::statement('SAVEPOINT second_order_same_cart_attempt');
        try {
            $this->makeOrder(cart: $cart);
            $this->fail('Expected a UNIQUE constraint violation for a second order on the same cart.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23505', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT second_order_same_cart_attempt');
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['cart_id' => $cart->id]);
    }

    public function test_checkout_creates_reserved_order_reserves_stock_and_marks_cart_checked_out(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(1000);
        $inventory = $this->makeInventory($product, 5);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => CartStatus::ACTIVE->value,
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price_cents' => 1000,
        ]);

        $order = app(CheckoutService::class)->checkout($cart);

        $this->assertSame(OrderStatus::RESERVED, $order->fresh()->status);
        $this->assertSame($cart->id, $order->cart_id);
        $this->assertSame(2000, $order->fresh()->total_cents);
        $this->assertSame(CartStatus::CHECKED_OUT, $cart->fresh()->status);
        $this->assertDatabaseCount('orders', 1);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'unit_price_cents' => 1000,
            'quantity' => 2,
            'line_total_cents' => 2000,
        ]);

        $this->assertSame(5, $inventory->fresh()->physical_stock);
        $this->assertSame(2, $inventory->fresh()->reserved_stock);
        $this->assertSame(3, $inventory->fresh()->available());

        $reservation = StockReservation::where('order_id', $order->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame(ReservationState::ACTIVE, $reservation->state);
        $this->assertNotNull($reservation->reserved_until);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $inventory->id,
            'type' => InventoryMovementType::RESERVATION->value,
            'quantity' => 2,
            'before_physical' => 5,
            'after_physical' => 5,
            'before_reserved' => 0,
            'after_reserved' => 2,
            'order_id' => $order->id,
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount_cents' => 2000,
            'status' => PaymentStatus::PENDING->value,
        ]);
    }

    public function test_checkout_of_the_same_cart_twice_creates_only_one_order(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(1000);
        $inventory = $this->makeInventory($product, 5);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => CartStatus::ACTIVE->value,
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price_cents' => 1000,
        ]);

        app(CheckoutService::class)->checkout($cart);

        try {
            app(CheckoutService::class)->checkout($cart);
            $this->fail('Expected the second checkout of the same cart to be rejected.');
        } catch (\InvalidArgumentException) {
            // expected: the cart is no longer active
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(CartStatus::CHECKED_OUT, $cart->fresh()->status);
    }

    public function test_checkout_of_an_empty_cart_is_rejected(): void
    {
        $user = User::factory()->create();
        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => CartStatus::ACTIVE->value,
        ]);

        try {
            app(CheckoutService::class)->checkout($cart);
            $this->fail('Expected an empty cart to be rejected.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(CartStatus::ACTIVE, $cart->fresh()->status);
    }

    public function test_checkout_of_a_non_active_cart_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(1000);
        $this->makeInventory($product, 5);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => CartStatus::CHECKED_OUT->value,
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price_cents' => 1000,
        ]);

        try {
            app(CheckoutService::class)->checkout($cart);
            $this->fail('Expected a non-active cart to be rejected.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_checkout_with_insufficient_stock_rolls_back_and_keeps_cart_active(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(1000);
        $inventory = $this->makeInventory($product, 1);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => CartStatus::ACTIVE->value,
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price_cents' => 1000,
        ]);

        try {
            app(CheckoutService::class)->checkout($cart);
            $this->fail('Expected an InsufficientStockException when the cart demands more than available.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(InsufficientStockException::CODE, $e::CODE);
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(1, $inventory->fresh()->physical_stock);
        $this->assertSame(0, $inventory->fresh()->reserved_stock);
        $this->assertSame(CartStatus::ACTIVE, $cart->fresh()->status);
    }
}
