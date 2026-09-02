<?php

namespace Tests\Feature;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthCartTest extends TestCase
{
    use RefreshDatabase;

    private function seedProduct(): Product
    {
        $product = Product::create([
            'sku' => 'SKU-T1',
            'name' => 'Test Product',
            'price_cents' => 50000,
            'description' => 'Test',
            'status' => 'active',
        ]);
        Inventory::create(['product_id' => $product->id, 'physical_stock' => 10, 'reserved_stock' => 0]);

        return $product;
    }

    public function test_register_returns_token_and_assigns_customer_role(): void
    {
        Role::create(['name' => 'customer']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'access_token']]);

        $user = User::where('email', 'ana@example.com')->first();
        $this->assertTrue($user->roles->contains('name', 'customer'));
        $this->assertDatabaseHas('carts', ['user_id' => $user->id, 'status' => CartStatus::ACTIVE->value]);
    }

    public function test_login_returns_token(): void
    {
        $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com', 'password_hash' => 'password123']);
        $this->postJson('/api/v1/auth/login', ['email' => 'budi@example.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_products_index_lists_active_products(): void
    {
        $this->seedProduct();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.products.data.0.name', 'Test Product')
            ->assertJsonPath('data.products.data.0.available', 10);
    }

    public function test_add_cart_item_requires_auth(): void
    {
        $this->postJson('/api/v1/cart/items', ['product_id' => 1, 'quantity' => 1])
            ->assertUnauthorized();
    }

    public function test_add_cart_item_merges_quantity_and_returns_cart(): void
    {
        $product = $this->seedProduct();
        $user = User::create(['name' => 'Citra', 'email' => 'citra@example.com', 'password_hash' => 'password123']);
        Cart::create(['user_id' => $user->id]);

        $token = auth('api')->login($user);

        $first = $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2]);
        $first->assertOk()->assertJsonPath('data.cart.total_cents', 100000);

        $second = $this->withToken($token)->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3]);
        $second->assertOk()->assertJsonPath('data.cart.items.0.quantity', 5);
    }

    public function test_add_cart_item_rejects_quantity_beyond_stock(): void
    {
        $product = $this->seedProduct();
        $user = User::create(['name' => 'Dedi', 'email' => 'dedi@example.com', 'password_hash' => 'password123']);
        Cart::create(['user_id' => $user->id]);

        $token = auth('api')->login($user);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 11])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
    }
}