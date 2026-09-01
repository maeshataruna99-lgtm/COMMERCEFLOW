<?php

namespace Tests\Feature;

use App\Enums\ReservationState;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StockReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PostgreSQL server ceiling (SHOW max_connections). Kept well above the
     * harness's peak: WORKER_CONCURRENCY worker processes + the harness process
     * itself, so connection exhaustion can never masquerade as an availability
     * conflict.
     */
    private const MAX_PG_CONNECTIONS = 100;

    /**
     * Peak worker concurrency per batch. Ceiling math: 25 workers + 1 harness
     * connection = 26 << 100.
     */
    private const WORKER_CONCURRENCY = 25;

    private const TOTAL_ATTEMPTS = 100;

    private const SEED_STOCK = 50;

    /**
     * RefreshDatabase normally wraps each test in a transaction. Spawned worker
     * processes cannot see uncommitted rows, so this override COMMITS the
     * migrations (migrate:fresh) and skips the transaction wrap entirely: every
     * seed written here is immediately visible to fresh connections.
     */
    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }
    }

    protected function tearDown(): void
    {
        // All harness writes are committed (no rollback wrap), so remove them to
        // keep the shared test DB clean for the rest of the suite.
        DB::statement('TRUNCATE stock_movements, stock_reservations, orders, inventories, products, users RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    public function test_100_concurrent_reservations_on_stock_50_succeed_exactly_once_each(): void
    {
        [$user, $product, $inventory] = $this->seedCommittedStock(self::SEED_STOCK);

        $exitCodes = $this->runReserveAttempts($product->id, 1, $user->id);

        $successes = count(array_filter($exitCodes, static fn (int $code): bool => $code === 0));
        $conflicts = count(array_filter($exitCodes, static fn (int $code): bool => $code === 2));
        $other = count($exitCodes) - $successes - $conflicts;

        $this->assertCount(self::TOTAL_ATTEMPTS, $exitCodes, 'All 100 workers must return a distinct exit code.');
        $this->assertSame(50, $successes, 'Exactly 50 of the 100 attempts must succeed.');
        $this->assertSame(50, $conflicts, 'The other 50 attempts must be availability conflicts (exit 2).');
        $this->assertSame(0, $other, 'No connection/DB error (exit 3) may masquerade as a conflict.');

        $row = DB::table('inventories')->where('id', $inventory->id)->first();
        $this->assertSame(50, (int) $row->physical_stock, 'physical_stock must never be decremented below 50.');
        $this->assertSame(50, (int) $row->reserved_stock, 'reserved_stock must equal the 50 successful reservations.');
        $this->assertGreaterThanOrEqual(
            (int) $row->reserved_stock,
            (int) $row->physical_stock,
            'oversold (reserved > physical) must be 0.',
        );

        $active = DB::table('stock_reservations')
            ->where('inventory_id', $inventory->id)
            ->where('state', ReservationState::ACTIVE->value)
            ->count();
        $this->assertSame(50, $active, 'Exactly 50 ACTIVE reservation rows must exist.');
    }

    public function test_checkout_requesting_more_than_available_is_rejected_with_a_conflict(): void
    {
        [$user, , $inventory] = $this->seedCommittedStock(1);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::uuid()->toString(),
            'status' => 'CREATED',
            'total_cents' => 0,
        ]);

        try {
            app(StockReservationService::class)->reserve($inventory, 2, $order, now()->addMinutes(10));
            $this->fail('Expected an InsufficientStockException for a 2-unit request on stock=1.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(InsufficientStockException::CODE, $e::CODE);
        }

        $row = DB::table('inventories')->where('id', $inventory->id)->first();
        $this->assertSame(1, (int) $row->physical_stock);
        $this->assertSame(0, (int) $row->reserved_stock);
        $this->assertSame(1, $inventory->fresh()->available(), 'Available stock must remain 1.');

        $this->assertSame(
            0,
            DB::table('stock_reservations')->where('inventory_id', $inventory->id)->count(),
            'No reservation row may be written for a rejected attempt.',
        );
        $this->assertSame(
            0,
            DB::table('stock_movements')->where('inventory_id', $inventory->id)->count(),
            'No movement row may be written for a rejected attempt.',
        );
    }

    public function test_a_failing_worker_returns_exit_code_2_for_insufficient_stock(): void
    {
        [$user, $product, $inventory] = $this->seedCommittedStock(1);

        $exitCodes = $this->runReserveAttempts($product->id, 2, $user->id, total: 1, concurrency: 1);

        $this->assertSame([2], $exitCodes, 'The worker must exit 2 when stock is insufficient.');

        $row = DB::table('inventories')->where('id', $inventory->id)->first();
        $this->assertSame(1, (int) $row->physical_stock);
        $this->assertSame(0, (int) $row->reserved_stock);
    }

    /**
     * @return array{0: User, 1: Product, 2: Inventory}
     */
    private function seedCommittedStock(int $physicalStock): array
    {
        $user = User::factory()->create();
        $product = Product::create([
            'sku' => 'CONC-'.Str::uuid()->toString(),
            'name' => 'Concurrency Widget',
            'price_cents' => 1000,
            'status' => 'active',
        ]);
        $inventory = Inventory::create([
            'product_id' => $product->id,
            'physical_stock' => $physicalStock,
            'reserved_stock' => 0,
        ]);

        // Spawn barrier: confirm the committed seed is visible to a FRESH
        // connection before firing any worker, so no worker can observe a
        // stale (uncommitted) state.
        $row = $this->freshPdo()
            ->query('SELECT physical_stock FROM inventories WHERE id = '.$inventory->id)
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((string) $physicalStock, (string) $row['physical_stock'], 'Seed must be visible to a fresh connection.');

        return [$user, $product, $inventory];
    }

    private function freshPdo(): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=commerceflow_test',
            'commerceflow',
            'commerceflow',
        );
    }

    /**
     * Spawn $total worker processes (each reserving $qty of $productId), at most
     * $concurrency running at once, and return every exit code in launch order.
     *
     * @return list<int>
     */
    private function runReserveAttempts(
        int $productId,
        int $qty,
        ?int $userId = null,
        int $total = self::TOTAL_ATTEMPTS,
        int $concurrency = self::WORKER_CONCURRENCY,
    ): array {
        $this->assertLessThan(
            self::MAX_PG_CONNECTIONS,
            $concurrency + 1,
            'Workers + harness connection must stay under PostgreSQL max_connections.',
        );

        $env = [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'BCRYPT_ROUNDS' => '4',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'commerceflow_test',
            'DB_USERNAME' => 'commerceflow',
            'DB_PASSWORD' => 'commerceflow',
        ];

        $baseArgs = [PHP_BINARY, 'artisan', 'reserve:attempt', (string) $productId, (string) $qty];
        if ($userId !== null) {
            $baseArgs[] = (string) $userId;
        }

        $exitCodes = [];
        $remaining = $total;

        while ($remaining > 0) {
            $batch = min($concurrency, $remaining);
            $processes = [];

            for ($i = 0; $i < $batch; $i++) {
                $process = new Process($baseArgs, dirname(__DIR__, 2), $env);
                $process->setTimeout(120);
                $process->start();
                $processes[] = $process;
            }

            foreach ($processes as $process) {
                $process->wait();
                $exitCodes[] = $process->getExitCode();
            }

            $remaining -= $batch;
        }

        return $exitCodes;
    }
}
