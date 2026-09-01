<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ReservationState;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch TTL expiry of reservations. Finds every order holding ACTIVE
 * reservations past their reserved_until and releases the reserved stock while
 * transitioning the order RESERVED -> EXPIRED.
 *
 * Each order is handled in isolation: one order's failure (or a race that made
 * it ineligible) is logged and skipped so it can never abort the rest of the
 * batch.
 */
final class ReservationExpiryService
{
    public function __construct(private readonly StockReservationService $stockReservationService)
    {
    }

    /**
     * Expire every order that currently holds an overdue ACTIVE reservation.
     * Safe to run repeatedly: expiry is idempotent (only ACTIVE reservations
     * are touched) and already-terminal orders are skipped.
     */
    public function expireAll(): void
    {
        $orderIds = StockReservation::query()
            ->where('state', ReservationState::ACTIVE->value)
            ->where('reserved_until', '<', now())
            ->distinct()
            ->pluck('order_id');

        foreach ($orderIds as $orderId) {
            $this->expireOrder((int) $orderId);
        }
    }

    private function expireOrder(int $orderId): void
    {
        try {
            DB::transaction(function () use ($orderId) {
                $order = Order::query()->find($orderId);

                if ($order === null) {
                    return;
                }

                // Expire ALL ACTIVE reservations for this order (atomic,
                // inventory-first lock ordering, idempotent). This writes one
                // RELEASE movement per reservation and decrements reserved_stock
                // once per reservation; physical_stock is left unchanged.
                $this->stockReservationService->expire($order);

                // Transition the order RESERVED -> EXPIRED exactly once. Skip
                // terminal states (EXPIRED/PAID/CANCELLED) that a concurrent
                // flow may have moved this order into — but the reservations
                // above are still expired regardless.
                if (in_array($order->status, [
                    OrderStatus::EXPIRED,
                    OrderStatus::PAID,
                    OrderStatus::CANCELLED,
                ], true)) {
                    return;
                }

                OrderTransitions::advance($order, OrderStatus::EXPIRED);
            });
        } catch (\Throwable $e) {
            Log::warning("Failed to expire reservations for order {$orderId}: ".$e->getMessage());
        }
    }
}
