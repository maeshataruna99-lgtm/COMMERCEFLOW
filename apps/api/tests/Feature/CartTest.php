<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        return Product::create([
            'sku' => 'SKU-'.Str::uuid()->toString(),
            'name' => 'Widget',
            'price_cents' => 1000,
            'status' => 'active',
        ]);
    }

    private function makeCart(?User $user = null): Cart
    {
        $user ??= User::factory()->create();

        return Cart::create([
            'user_id' => $user->id,
            'status' => CartStatus::ACTIVE->value,
        ]);
    }

    public function test_cart_persists_with_active_status_and_resolves_user(): void
    {
        $user = User::factory()->create();
        $cart = $this->makeCart($user);

        $this->assertSame(CartStatus::ACTIVE, $cart->status);
        $this->assertSame($user->id, $cart->user->id);
        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'user_id' => $user->id,
            'status' => CartStatus::ACTIVE->value,
        ]);
    }

    public function test_second_line_for_the_same_product_is_rejected_by_unique_constraint(): void
    {
        $cart = $this->makeCart();
        $product = $this->makeProduct();

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price_cents' => 1000,
        ]);

        DB::statement('SAVEPOINT duplicate_line_attempt');
        try {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price_cents' => 1000,
            ]);
            $this->fail('Expected a UNIQUE constraint violation for duplicate (cart_id, product_id).');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23505', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT duplicate_line_attempt');
        }

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_line_with_quantity_zero_is_rejected_by_check_constraint(): void
    {
        $cart = $this->makeCart();
        $product = $this->makeProduct();

        DB::statement('SAVEPOINT zero_quantity_attempt');
        try {
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => 0,
                'price_cents' => 1000,
            ]);
            $this->fail('Expected a CHECK constraint violation for quantity = 0.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23514', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT zero_quantity_attempt');
        }

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_line_snapshots_price_cents_and_resolves_cart_and_product(): void
    {
        $cart = $this->makeCart();
        $product = $this->makeProduct();

        $item = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price_cents' => 1000,
        ]);

        $this->assertSame($cart->id, $item->cart->id);
        $this->assertSame($product->id, $item->product->id);
        $this->assertTrue($cart->cartItems->contains($item));
        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price_cents' => 1000,
        ]);
    }
}
