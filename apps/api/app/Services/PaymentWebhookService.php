<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentWebhookResult;
use App\Enums\ReservationState;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Single-transaction webhook handler (spec §6.5). The idempotency insert and
 * every status update — including rejections — commit atomically, so a crash
 * rolls everything back and redelivery retries cleanly.
 *
 * The `payments` row is created (status PENDING) at checkout, so every
 * `payment_transactions.payment_id` FK has a target even for rejected webhooks.
 *
 * Lock order: order row → payment row → (via consume) inventory rows ascending
 * inventory_id → reservation rows — the same global order as checkout,
 * expiry, cancellation and refund, eliminating the AB-BA deadlock window.
 */
final class PaymentWebhookService
{
    public function __construct(private readonly StockReservationService $reservations) {}

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function handle(
        Order $order,
        string $idempotencyKey,
        int $amountCents,
        ?string $providerReference = null,
        array $rawPayload = [],
        string $providerStatus = PaymentTransactionStatus::SUCCEEDED->value,
    ): PaymentWebhookResult {
        try {
            return DB::transaction(function () use ($order, $idempotencyKey, $amountCents, $providerReference, $rawPayload, $providerStatus): PaymentWebhookResult {
                $lockedOrder = Order::query()
                    ->whereKey($order->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $payment = Payment::query()
                    ->where('order_id', $lockedOrder->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $transaction = PaymentTransaction::create([
                    'payment_id' => $payment->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'provider_reference' => $providerReference,
                    'status' => PaymentTransactionStatus::PENDING->value,
                    'amount_cents' => $amountCents,
                    'raw_payload' => $rawPayload,
                ]);

                if ($lockedOrder->status === OrderStatus::PAID) {
                    // The order is already paid: a successful webhook for it is a
                    // duplicate/replay regardless of key. Record the delivery as
                    // SUCCEEDED (traceable) but never re-apply state.
                    $transaction->update(['status' => PaymentTransactionStatus::SUCCEEDED->value]);

                    return PaymentWebhookResult::ALREADY_HANDLED;
                }

                if ($providerStatus !== PaymentTransactionStatus::SUCCEEDED->value) {
                    $transaction->update(['status' => PaymentTransactionStatus::FAILED->value]);

                    return PaymentWebhookResult::FAILED;
                }

                if ($amountCents !== (int) $lockedOrder->total_cents) {
                    $this->reject($transaction, $rawPayload, 'amount_mismatch');

                    return PaymentWebhookResult::REJECTED;
                }

                $hasNonActiveReservation = $lockedOrder->stockReservations()
                    ->where('state', '!=', ReservationState::ACTIVE->value)
                    ->exists();

                if ($hasNonActiveReservation) {
                    $this->reject($transaction, $rawPayload, 'reservation_not_active');

                    return PaymentWebhookResult::REJECTED;
                }

                // Delegate consumption to the sole reservation mutator. consume()
                // returns the number of ACTIVE reservations actually transitioned;
                // 0 means a concurrent expiry/cancel won (or none existed), which
                // must surface as REJECTED, never success.
                if ($this->reservations->consume($lockedOrder) === 0) {
                    $this->reject($transaction, $rawPayload, 'no_active_reservations');

                    return PaymentWebhookResult::REJECTED;
                }

                OrderTransitions::advance($lockedOrder, OrderStatus::PAID);

                $payment->update(['status' => PaymentStatus::PAID->value]);

                $transaction->update(['status' => PaymentTransactionStatus::SUCCEEDED->value]);

                return PaymentWebhookResult::PROCESSED;
            });
        } catch (QueryException $e) {
            // The only INSERT in the transaction is payment_transactions, so a
            // unique violation can only be the idempotency_key: the event was
            // already delivered and processed (or recorded as rejected). The
            // failed transaction is rolled back by DB::transaction, so this is
            // a clean idempotent skip.
            if ($e->getCode() === '23505'
                && str_contains($e->getMessage(), 'payment_transactions_idempotency_key_unique')) {
                return PaymentWebhookResult::ALREADY_HANDLED;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function reject(PaymentTransaction $transaction, array $rawPayload, string $reason): void
    {
        $transaction->update([
            'status' => PaymentTransactionStatus::REJECTED->value,
            'raw_payload' => array_merge($rawPayload, ['rejection_reason' => $reason]),
        ]);
    }
}
