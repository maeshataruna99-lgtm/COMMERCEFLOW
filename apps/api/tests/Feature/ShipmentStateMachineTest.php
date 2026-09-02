<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShipmentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipment_follows_valid_forward_transitions(): void
    {
        $this->assertTrue(ShipmentStatus::CREATED->canTransitionTo(ShipmentStatus::PACKED));
        $this->assertTrue(ShipmentStatus::PACKED->canTransitionTo(ShipmentStatus::SHIPPED));
        $this->assertTrue(ShipmentStatus::SHIPPED->canTransitionTo(ShipmentStatus::DELIVERED));
    }

    public function test_illegal_backward_and_skip_transitions_are_rejected(): void
    {
        $this->assertFalse(ShipmentStatus::DELIVERED->canTransitionTo(ShipmentStatus::PACKED));
        $this->assertFalse(ShipmentStatus::SHIPPED->canTransitionTo(ShipmentStatus::CREATED));
        $this->assertFalse(ShipmentStatus::CREATED->canTransitionTo(ShipmentStatus::SHIPPED));
        $this->assertFalse(ShipmentStatus::CREATED->canTransitionTo(ShipmentStatus::DELIVERED));
        $this->assertFalse(ShipmentStatus::PACKED->canTransitionTo(ShipmentStatus::DELIVERED));
    }

    public function test_shipment_created_against_a_paid_order_defaults_to_created(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::uuid()->toString(),
            'status' => OrderStatus::PAID->value,
            'total_cents' => 1000,
        ]);

        $shipment = Shipment::create(['order_id' => $order->id]);

        $this->assertSame(ShipmentStatus::CREATED, $shipment->fresh()->status);
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'order_id' => $order->id,
            'status' => ShipmentStatus::CREATED->value,
        ]);
    }

    public function test_invalid_status_value_is_rejected_by_check_constraint(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::uuid()->toString(),
            'status' => OrderStatus::PAID->value,
            'total_cents' => 1000,
        ]);

        DB::statement('SAVEPOINT invalid_shipment_status_attempt');
        try {
            DB::table('shipments')->insert([
                'order_id' => $order->id,
                'status' => 'BOGUS',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected a CHECK constraint violation for an out-of-set status.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23514', $e->getMessage());
            DB::statement('ROLLBACK TO SAVEPOINT invalid_shipment_status_attempt');
        }

        $this->assertDatabaseCount('shipments', 0);
    }
}
