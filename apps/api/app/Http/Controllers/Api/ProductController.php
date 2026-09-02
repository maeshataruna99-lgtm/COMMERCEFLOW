<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->where('status', ProductStatus::ACTIVE->value)
            ->with('inventory')
            ->orderBy('id')
            ->paginate(24);

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products->through(fn (Product $product) => $this->serialize($product)),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ],
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        if ($product->status !== ProductStatus::ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
                'error' => ['code' => 'NOT_FOUND'],
            ], 404);
        }

        $product->load('inventory');

        return response()->json([
            'success' => true,
            'data' => ['product' => $this->serialize($product)],
        ]);
    }

    private function serialize(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'price_cents' => $product->price_cents,
            'available' => $product->inventory?->available ?? 0,
        ];
    }
}