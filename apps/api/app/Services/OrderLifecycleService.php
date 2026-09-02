<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\ReservationState;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Order-scoped lifecycle operations (cancel/refund). Both are exactly-once:
 * the order row is locked FOR UPDATE first (serializing concurrent calls),
 * inventory rows are locked in global ascending order (F2.5), reservation
 * release/consume is delegated to StockReservationService, and refund RETURN
 * movements are written here via MovementLedger.
 */
final class OrderLifecycleService
{
    public function __construct(private readonly StockReservationService $reservations) {}

    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status === OrderStatus::CANCELLED) {
                return $lockedOrder;
            }

            if ($lockedOrder->status === OrderStatus::RESERVED) {
                $this->reservations->release($lockedOrder);

                return OrderTransitions::advance($lockedOrder, OrderStatus::CANCELLED);
            }

            if ($lockedOrder->status === OrderStatus::CREATED) {
                return OrderTransitions::advance($lockedOrder, OrderStatus::CANCELLED);
            }

            throw new IllegalOrderTransitionException($lockedOrder->status, OrderStatus::CANCELLED);
        });
    }

    public function refund(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status === OrderStatus::REFUNDED) {
                return $lockedOrder;
            }

            if (! in_array($lockedOrder->status, [
                OrderStatus::PAID,
                OrderStatus::PACKED,
                OrderStatus::SHIPPED,
                OrderStatus::COMPLETED,
            ], true)) {
                throw new IllegalOrderTransitionException($lockedOrder->status, OrderStatus::REFUNDED);
            }

            $this->writeReturnMovements($lockedOrder);

            return OrderTransitions::advance($lockedOrder, OrderStatus::REFUNDED);
        });
    }

    /**
     * Reservations were already consumed at payment time (SALE movements
     * decremented reserved AND physical). A refund restores the physical side
     * via one RETURN movement per consumed reservation.
     */
    private function writeReturnMovements(Order $order): void
    {
        $reservations = $order->stockReservations()
            ->where('state', ReservationState::CONSUMED->value)
            ->get();

        $inventoryIds = $reservations
            ->pluck('inventory_id')
            ->unique()
            ->sort()
            ->values();

        $lockedInventories = Inventory::query()
            ->whereIn('id', $inventoryIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($reservations as $reservation) {
            $inventory = $lockedInventories->get($reservation->inventory_id);

            if ($inventory === null) {
                throw new \RuntimeException('Missing inventory row for reservation '.$reservation->getKey().'.');
            }

            $delta = MovementLedger::apply($inventory, InventoryMovementType::RETURN, (int) $reservation->quantity);

            $inventory->update([
                'physical_stock' => $delta['afterPhysical'],
            ]);

            StockMovement::create([
                'inventory_id' => $inventory->getKey(),
                'type' => InventoryMovementType::RETURN->value,
                'quantity' => (int) $reservation->quantity,
                'before_physical' => $delta['beforePhysical'],
                'after_physical' => $delta['afterPhysical'],
                'before_reserved' => $delta['beforeReserved'],
                'after_reserved' => $delta['afterReserved'],
                'reservation_id' => $reservation->getKey(),
                'reason' => 'refund return',
            ]);
        }
    }
}
