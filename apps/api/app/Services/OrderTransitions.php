<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Order;

/**
 * Single enforcement contract for order state transitions. Every code path
 * that changes an order's status (harness, lifecycle service, webhooks, API)
 * must go through this class.
 */
final class OrderTransitions
{
    public static function assertCanTransition(OrderStatus $current, OrderStatus $target): void
    {
        if (! $current->canTransitionTo($target)) {
            throw new IllegalOrderTransitionException($current, $target);
        }
    }

    public static function advance(Order $order, OrderStatus $target): Order
    {
        self::assertCanTransition($order->status, $target);

        $order->status = $target;
        $order->save();

        return $order;
    }
}
