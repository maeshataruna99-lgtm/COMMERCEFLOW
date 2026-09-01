<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Enums\ReservationState;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sole owner of reservation-state mutation. reserve() is the availability gate
 * for every reserve path; the DB `SELECT ... FOR UPDATE` is the correctness
 * guard (Redis lock acquisition is an accepted scope cut, tracked in the
 * plan-progress decision log F2.13).
 */
final class StockReservationService
{
    public function reserve(Inventory $inventory, int $qty, Order $order, Carbon $reservedUntil): StockReservation
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be a positive integer.');
        }

        return DB::transaction(function () use ($inventory, $qty, $order, $reservedUntil) {
            /** @var Inventory|null $locked */
            $locked = Inventory::query()
                ->whereKey($inventory->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new \RuntimeException('Missing inventory row for product '.$inventory->product_id.'.');
            }

            $available = MovementLedger::available((int) $locked->physical_stock, (int) $locked->reserved_stock);
            if ($available < $qty) {
                throw new InsufficientStockException(
                    sprintf('Available stock %d is less than the %d requested.', $available, $qty),
                );
            }

            $delta = MovementLedger::apply($locked, InventoryMovementType::RESERVATION, $qty);

            $reservation = StockReservation::create([
                'order_id' => $order->getKey(),
                'product_id' => $locked->product_id,
                'inventory_id' => $locked->getKey(),
                'quantity' => $qty,
                'state' => ReservationState::ACTIVE->value,
                'reserved_until' => $reservedUntil,
            ]);

            $locked->update([
                'reserved_stock' => $delta['afterReserved'],
            ]);

            StockMovement::create([
                'inventory_id' => $locked->getKey(),
                'type' => InventoryMovementType::RESERVATION->value,
                'quantity' => $qty,
                'before_physical' => $delta['beforePhysical'],
                'after_physical' => $delta['afterPhysical'],
                'before_reserved' => $delta['beforeReserved'],
                'after_reserved' => $delta['afterReserved'],
                'order_id' => $order->getKey(),
                'reason' => 'checkout reservation',
            ]);

            return $reservation;
        });
    }

    public function consume(Order $order): void
    {
        $this->transitionActiveReservations($order, ReservationState::CONSUMED, InventoryMovementType::SALE);
    }

    public function release(Order $order): void
    {
        $this->transitionActiveReservations($order, ReservationState::RELEASED, InventoryMovementType::RELEASE);
    }

    public function expire(Order $order): void
    {
        $this->transitionActiveReservations($order, ReservationState::EXPIRED, InventoryMovementType::RELEASE);
    }

    public function releaseReservation(StockReservation $reservation): void
    {
        if ($reservation->state !== ReservationState::ACTIVE) {
            return;
        }

        DB::transaction(function () use ($reservation) {
            $locked = Inventory::query()
                ->whereKey($reservation->inventory_id)
                ->lockForUpdate()
                ->firstOrFail();

            $delta = MovementLedger::apply($locked, InventoryMovementType::RELEASE, (int) $reservation->quantity);

            $locked->update([
                'reserved_stock' => $delta['afterReserved'],
            ]);

            StockMovement::create([
                'inventory_id' => $locked->getKey(),
                'type' => InventoryMovementType::RELEASE->value,
                'quantity' => (int) $reservation->quantity,
                'before_physical' => $delta['beforePhysical'],
                'after_physical' => $delta['afterPhysical'],
                'before_reserved' => $delta['beforeReserved'],
                'after_reserved' => $delta['afterReserved'],
                'reservation_id' => $reservation->getKey(),
                'reason' => 'release single reservation',
            ]);

            $reservation->update(['state' => ReservationState::RELEASED->value]);
        });
    }

    /**
     * Idempotent lifecycle helper: only ACTIVE reservations are touched, so a
     * second consume/release/expire call is a no-op.
     */
    private function transitionActiveReservations(
        Order $order,
        ReservationState $targetState,
        InventoryMovementType $movementType,
    ): void {
        DB::transaction(function () use ($order, $targetState, $movementType) {
            $reservations = $order->stockReservations()
                ->where('state', ReservationState::ACTIVE->value)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $locked = Inventory::query()
                    ->whereKey($reservation->inventory_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $delta = MovementLedger::apply($locked, $movementType, (int) $reservation->quantity);

                $locked->update([
                    'reserved_stock' => $delta['afterReserved'],
                ]);

                StockMovement::create([
                    'inventory_id' => $locked->getKey(),
                    'type' => $movementType->value,
                    'quantity' => (int) $reservation->quantity,
                    'before_physical' => $delta['beforePhysical'],
                    'after_physical' => $delta['afterPhysical'],
                    'before_reserved' => $delta['beforeReserved'],
                    'after_reserved' => $delta['afterReserved'],
                    'reservation_id' => $reservation->getKey(),
                    'reason' => 'lifecycle '.strtolower($targetState->value),
                ]);

                $reservation->update(['state' => $targetState->value]);
            }
        });
    }
}
