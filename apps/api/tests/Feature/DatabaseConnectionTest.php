<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgsql_connection_reaches_the_shared_test_database(): void
    {
        $pdo = DB::connection()->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('commerceflow_test', DB::connection()->getDatabaseName());
        $this->assertSame(1, DB::scalar('select 1'));
    }
}
