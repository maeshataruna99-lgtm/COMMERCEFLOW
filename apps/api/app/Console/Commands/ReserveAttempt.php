<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Concurrency-harness worker: reserves {qty} units of {productId} under a fresh
 * order. Exit codes are the harness contract: 0 = success, 2 = insufficient
 * stock (availability conflict), 3 = connection/DB/other error.
 */
class ReserveAttempt extends Command
{
    protected $signature = 'reserve:attempt {productId} {qty} {userId?}';

    protected $description = 'Reserve {qty} units of {productId} under a fresh order (concurrency harness worker)';

    public function __construct(private readonly StockReservationService $stockReservationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $productId = (int) $this->argument('productId');
        $qty = (int) $this->argument('qty');

        try {
            $product = Product::findOrFail($productId);
            $inventory = $product->inventory;

            if ($inventory === null) {
                throw new \RuntimeException("Product {$productId} has no inventory row.");
            }

            $user = $this->resolveUser($this->argument('userId'));

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => 'RES-'.Str::uuid()->toString(),
                'status' => OrderStatus::CREATED->value,
                'total_cents' => 0,
            ]);

            $this->stockReservationService->reserve($inventory, $qty, $order, now()->addMinutes(10));

            return self::SUCCESS;
        } catch (InsufficientStockException $e) {
            $this->error('insufficient stock: '.$e->getMessage());

            return 2;
        } catch (\Throwable $e) {
            $this->error('error: '.$e->getMessage());

            return 3;
        }
    }

    private function resolveUser(mixed $userId): User
    {
        if ($userId !== null) {
            return User::findOrFail((int) $userId);
        }

        return User::create([
            'name' => 'Reserve Attempt',
            'email' => 'reserve-'.Str::uuid()->toString().'@example.test',
            'password_hash' => 'worker-secret',
        ]);
    }
}
