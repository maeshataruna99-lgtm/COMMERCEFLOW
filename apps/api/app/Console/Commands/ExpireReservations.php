<?php

namespace App\Console\Commands;

use App\Services\ReservationExpiryService;
use Illuminate\Console\Command;

/**
 * Batch TTL expiry job for reservations. Invokable directly and schedulable via
 * the Laravel scheduler / cron, e.g. in routes/console.php:
 *
 *     Schedule::command('reservations:expire')->everyMinute();
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire overdue reservations and release their reserved stock';

    public function __construct(private readonly ReservationExpiryService $expiryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->expiryService->expireAll();

        $this->info('Overdue reservations expired.');

        return self::SUCCESS;
    }
}
