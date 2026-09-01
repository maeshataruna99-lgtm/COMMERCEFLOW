<?php

namespace App\Services;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checkout orchestrator. It only coordinates the atomic order creation:
 * the cart is locked and flipped to checked_out in the same transaction,
 * inventory rows are locked in global ascending order (F2.5), and every
 * availability decision + reservation mutation is delegated to
 * StockReservationService::reserve() — the sole mutator/availability gate.
 */
final class CheckoutService
{
    public function __construct(private readonly StockReservationService $reservations) {}

    public function checkout(Cart $cart, ?Carbon $reservedUntil = null): Order
    {
        return DB::transaction(function () use ($cart, $reservedUntil): Order {
            $lockedCart = Cart::query()
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCart->status !== CartStatus::ACTIVE) {
                throw new \InvalidArgumentException('Only an active cart can be checked out.');
            }

            $items = $lockedCart->cartItems()->with('product')->get();

            if ($items->isEmpty()) {
                throw new \InvalidArgumentException('Cannot check out an empty cart.');
            }

            $inventoryIds = $items
                ->map(fn (CartItem $item): ?int => $item->product->inventory?->getKey())
                ->filter()
                ->unique()
                ->sort()
                ->values();

            Inventory::query()
                ->whereIn('id', $inventoryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $order = Order::create([
                'user_id' => $lockedCart->user_id,
                'order_number' => 'ORD-'.Str::uuid()->toString(),
                'status' => OrderStatus::RESERVED->value,
                'total_cents' => 0,
                'cart_id' => $lockedCart->getKey(),
            ]);

            $reserveUntil = $reservedUntil ?? now()->addMinutes((int) config('commerceflow.reservation_ttl_minutes', 30));

            foreach ($items as $item) {
                $inventory = $item->product->inventory;

                if ($inventory === null) {
                    throw new \RuntimeException('Missing inventory row for product '.$item->product_id.'.');
                }

                $this->reservations->reserve($inventory, (int) $item->quantity, $order, $reserveUntil);

                $unitPrice = (int) $item->product->price_cents;

                OrderItem::create([
                    'order_id' => $order->getKey(),
                    'product_id' => $item->product_id,
                    'unit_price_cents' => $unitPrice,
                    'quantity' => (int) $item->quantity,
                    'line_total_cents' => $unitPrice * (int) $item->quantity,
                ]);
            }

            $lockedCart->update(['status' => CartStatus::CHECKED_OUT->value]);

            return $order->fresh();
        });
    }
}
