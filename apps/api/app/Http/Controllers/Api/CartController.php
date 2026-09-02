<?php

namespace App\Http\Controllers\Api;

use App\Enums\CartStatus;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request->user()->id);
        $cart->load(['cartItems.product.inventory']);

        return response()->json([
            'success' => true,
            'data' => [
                'cart' => [
                    'id' => $cart->id,
                    'status' => $cart->status->value,
                    'items' => $cart->cartItems->map(fn (CartItem $item) => [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'name' => $item->product?->name,
                        'price_cents' => $item->price_cents,
                        'quantity' => $item->quantity,
                        'line_total_cents' => $item->price_cents * $item->quantity,
                    ]),
                    'total_cents' => $cart->cartItems->sum(fn (CartItem $item) => $item->price_cents * $item->quantity),
                ],
            ],
        ]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::query()->with('inventory')->findOrFail($data['product_id']);
        $cart = $this->cartFor($request->user()->id);

        if ($cart->status !== CartStatus::ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is not active',
                'error' => ['code' => 'CART_NOT_ACTIVE'],
            ], 409);
        }

        $available = $product->inventory?->available ?? 0;
        $existing = $cart->cartItems()->where('product_id', $product->id)->first();
        $requestedQty = $data['quantity'] + ($existing?->quantity ?? 0);

        if ($requestedQty > $available) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock',
                'error' => ['code' => 'INSUFFICIENT_STOCK'],
            ], 409);
        }

        if ($existing) {
            $existing->update(['quantity' => $requestedQty]);
        } else {
            $cart->cartItems()->create([
                'product_id' => $product->id,
                'quantity' => $data['quantity'],
                'price_cents' => $product->price_cents,
            ]);
        }

        return $this->show($request);
    }

    public function removeItem(Request $request, CartItem $cartItem): JsonResponse
    {
        $cart = $this->cartFor($request->user()->id);

        if ($cartItem->cart_id !== $cart->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found',
                'error' => ['code' => 'NOT_FOUND'],
            ], 404);
        }

        $cartItem->delete();

        return $this->show($request);
    }

    private function cartFor(int $userId): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $userId],
            ['status' => CartStatus::ACTIVE->value]
        );
    }
}