<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Enums\ReservationState;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Database\QueryException;
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

    /**
     * Consume all ACTIVE reservations as SALE movements. Returns the number of
     * reservations actually transitioned, so a caller can distinguish a
     * successful consumption from a 0-ACTIVE-rows abort (reservations already
     * EXPIRED/RELEASED/CONSUMED — e.g. an in-flight expiry beat the payment),
     * which must never be reported as success.
     */
    public function consume(Order $order): int
    {
        return $this->transitionActiveReservations($order, ReservationState::CONSUMED, InventoryMovementType::SALE);
    }

    public function release(Order $order): int
    {
        return $this->transitionActiveReservations($order, ReservationState::RELEASED, InventoryMovementType::RELEASE);
    }

    public function expire(Order $order): int
    {
        return $this->transitionActiveReservations($order, ReservationState::EXPIRED, InventoryMovementType::RELEASE);
    }

    public function releaseReservation(StockReservation $reservation): void
    {
        // Fast path only. The authoritative guard is the state=ACTIVE re-select
        // inside the transaction: the caller's instance may be stale, so a
        // second concurrent release must not double-decrement reserved_stock.
        if ($reservation->state !== ReservationState::ACTIVE) {
            return;
        }

        $this->retryOnSerialization(function () use ($reservation) {
            DB::transaction(function () use ($reservation) {
                // Lock ordering is inventory-before-reservation (same as
                // transitionActiveReservations / spec §6.4 / F2.5): the inventory
                // row is locked first, then the reservation row, eliminating the
                // AB-BA deadlock window between the two paths.
                $locked = Inventory::query()
                    ->whereKey($reservation->inventory_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var StockReservation|null $lockedReservation */
                $lockedReservation = StockReservation::query()
                    ->whereKey($reservation->getKey())
                    ->where('state', ReservationState::ACTIVE->value)
                    ->lockForUpdate()
                    ->first();

                if ($lockedReservation === null) {
                    return; // already released/consumed/expired: no-op
                }

                $delta = MovementLedger::apply($locked, InventoryMovementType::RELEASE, (int) $lockedReservation->quantity);

                $locked->update([
                    'reserved_stock' => $delta['afterReserved'],
                ]);

                StockMovement::create([
                    'inventory_id' => $locked->getKey(),
                    'type' => InventoryMovementType::RELEASE->value,
                    'quantity' => (int) $lockedReservation->quantity,
                    'before_physical' => $delta['beforePhysical'],
                    'after_physical' => $delta['afterPhysical'],
                    'before_reserved' => $delta['beforeReserved'],
                    'after_reserved' => $delta['afterReserved'],
                    'reservation_id' => $lockedReservation->getKey(),
                    'reason' => 'release single reservation',
                ]);

                $lockedReservation->update(['state' => ReservationState::RELEASED->value]);
            });
        });
    }

    /**
     * Idempotent lifecycle helper: only ACTIVE reservations are touched, so a
     * second consume/release/expire call is a no-op.
     *
     * Lock ordering is inventory-before-reservation (spec §6.4 / F2.5): the
     * inventory rows are locked first in ascending id, then the reservation
     * rows. releaseReservation() follows the same order (inventory, then the
     * single reservation row), eliminating the AB-BA deadlock window between
     * the two paths.
     */
    private function transitionActiveReservations(
        Order $order,
        ReservationState $targetState,
        InventoryMovementType $movementType,
    ): int {
        $transitioned = 0;

        $this->retryOnSerialization(function () use ($order, $targetState, $movementType, &$transitioned) {
            DB::transaction(function () use ($order, $targetState, $movementType, &$transitioned) {
                $reservations = $order->stockReservations()
                    ->where('state', ReservationState::ACTIVE->value)
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

                $lockedReservations = StockReservation::query()
                    ->whereIn('id', $reservations->pluck('id'))
                    ->where('state', ReservationState::ACTIVE->value)
                    ->lockForUpdate()
                    ->get();

                $transitioned = $lockedReservations->count();

                foreach ($lockedReservations as $reservation) {
                    $locked = $lockedInventories->get($reservation->inventory_id);

                    if ($locked === null) {
                        throw new \RuntimeException('Missing inventory row for reservation '.$reservation->getKey().'.');
                    }

                    $delta = MovementLedger::apply($locked, $movementType, (int) $reservation->quantity);

                    // SALE also decrements physical (spec §6.4 consumption step
                    // 7); RELEASE/EXPIRED leave physical unchanged. Persisting
                    // both keeps the inventory row in lockstep with the ledger.
                    $locked->update([
                        'physical_stock' => $delta['afterPhysical'],
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
        });

        return $transitioned;
    }

    /**
     * Retry a transaction a bounded number of times when PostgreSQL reports a
     * serialization failure (40001) or deadlock (40P01). These are transient
     * concurrency errors, so a small linear backoff then a retry is safe.
     */
    private function retryOnSerialization(callable $operation, int $maxAttempts = 3): void
    {
        $attempt = 0;

        while (true) {
            try {
                $attempt++;
                $operation();

                return;
            } catch (QueryException $e) {
                $sqlState = (string) $e->getCode();
                $isTransient = in_array($sqlState, ['40001', '40P01'], true);

                if (! $isTransient || $attempt >= $maxAttempts) {
                    throw $e;
                }

                usleep(50_000 * $attempt);
            }
        }
    }
}
