<?php

namespace App\Services;

use App\Enums\InventoryAdjustmentType;
use App\Enums\InventoryMovementType;
use App\Enums\ReservationState;
use App\Exceptions\InvalidStockAdjustmentException;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

/**
 * Owns manual stock adjustments. A downward ADJUSTMENT that would violate the
 * reserved_stock <= physical_stock invariant first releases the reservations it
 * renders unsupported (ascending reserved_until), then applies the movement —
 * all within a single transaction — preserving the invariant for any legitimate
 * count correction.
 */
final class InventoryAdjustmentService
{
    public function __construct(
        private readonly StockReservationService $stockReservationService,
    ) {
    }

    public function adjust(Inventory $inventory, int $delta, ?int $userId = null): Inventory
    {
        if ($delta === 0) {
            throw new InvalidStockAdjustmentException('Adjustment delta must be non-zero.');
        }

        return DB::transaction(function () use ($inventory, $delta, $userId) {
            /** @var Inventory|null $locked */
            $locked = Inventory::query()
                ->whereKey($inventory->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new \RuntimeException('Missing inventory row for product '.$inventory->product_id.'.');
            }

            $beforePhysical = (int) $locked->physical_stock;
            $beforeReserved = (int) $locked->reserved_stock;
            $targetPhysical = $beforePhysical + $delta;

            if ($targetPhysical < 0) {
                throw new InvalidStockAdjustmentException(
                    sprintf('Adjustment of %d would take physical stock below zero (current %d).', $delta, $beforePhysical),
                );
            }

            $this->releaseUnsupportedReservations($locked, $targetPhysical);

            $fresh = $locked->fresh();
            $afterReserved = (int) $fresh->reserved_stock;

            $movement = MovementLedger::apply($fresh, InventoryMovementType::ADJUSTMENT, $delta);

            $fresh->update([
                'physical_stock' => $movement['afterPhysical'],
            ]);

            StockMovement::create([
                'inventory_id' => $fresh->getKey(),
                'type' => InventoryMovementType::ADJUSTMENT->value,
                'quantity' => abs($delta),
                'before_physical' => $movement['beforePhysical'],
                'after_physical' => $movement['afterPhysical'],
                'before_reserved' => $movement['beforeReserved'],
                'after_reserved' => $movement['afterReserved'],
                'reason' => $this->reason($delta),
            ]);

            AuditLog::record(
                'inventory.adjustment',
                'inventory',
                $fresh->getKey(),
                ['physical_stock' => $beforePhysical, 'reserved_stock' => $beforeReserved],
                ['physical_stock' => $movement['afterPhysical'], 'reserved_stock' => $afterReserved],
                $userId,
            );

            return $fresh;
        });
    }

    /**
     * Release ACTIVE reservations (ascending reserved_until, then id) until
     * reserved_stock <= targetPhysical. No-op when the invariant already holds.
     */
    private function releaseUnsupportedReservations(Inventory $locked, int $targetPhysical): void
    {
        while ((int) $locked->reserved_stock > $targetPhysical) {
            /** @var StockReservation|null $toRelease */
            $toRelease = StockReservation::query()
                ->where('inventory_id', $locked->getKey())
                ->where('state', ReservationState::ACTIVE->value)
                ->orderByRaw('reserved_until ASC NULLS LAST')
                ->orderBy('id')
                ->first();

            if ($toRelease === null) {
                throw new \RuntimeException(
                    'Cannot satisfy reserved <= physical: no ACTIVE reservation available to release.',
                );
            }

            $this->stockReservationService->releaseReservation($toRelease);

            // The inventory row is updated inside releaseReservation; refresh it.
            $locked->refresh();
        }
    }

    private function reason(int $delta): string
    {
        $type = $delta < 0 ? InventoryAdjustmentType::REDUCE : InventoryAdjustmentType::ADD;

        return 'manual stock adjustment ('.strtolower($type->value).')';
    }
}
