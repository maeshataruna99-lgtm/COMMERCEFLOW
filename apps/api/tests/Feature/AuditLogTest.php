<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_creates_a_row_with_before_after_actor_and_timestamp(): void
    {
        $user = User::factory()->create();

        $log = AuditLog::record(
            'inventory.adjustment',
            'inventory',
            42,
            ['physical_stock' => 10],
            ['physical_stock' => 7],
            $user->id,
        );

        $this->assertNotNull($log->id);
        $this->assertSame('inventory.adjustment', $log->action);
        $this->assertSame('inventory', $log->entity_type);
        $this->assertSame(42, $log->entity_id);
        $this->assertSame(['physical_stock' => 10], $log->before);
        $this->assertSame(['physical_stock' => 7], $log->after);
        $this->assertSame($user->id, $log->user_id);
        $this->assertNotNull($log->created_at);
    }

    public function test_record_allows_null_actor_before_and_after(): void
    {
        $log = AuditLog::record('system.event', 'product', 7, null, null, null);

        $this->assertNotNull($log->id);
        $this->assertNull($log->user_id);
        $this->assertNull($log->before);
        $this->assertNull($log->after);
        $this->assertNull($log->before);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.event',
            'entity_type' => 'product',
            'entity_id' => 7,
            'user_id' => null,
        ]);
    }
}
