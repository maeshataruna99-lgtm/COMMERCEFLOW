<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Order;
use App\Services\OrderTransitions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class OrderTransitionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @var array<string, list<string>>
     */
    private const EXPECTED_MATRIX = [
        'CREATED' => ['RESERVED', 'CANCELLED'],
        'RESERVED' => ['PAID', 'CANCELLED', 'EXPIRED'],
        'PAID' => ['PACKED', 'REFUNDED'],
        'PACKED' => ['SHIPPED', 'REFUNDED'],
        'SHIPPED' => ['COMPLETED', 'REFUNDED'],
        'COMPLETED' => ['REFUNDED'],
        'CANCELLED' => [],
        'EXPIRED' => [],
        'REFUNDED' => [],
    ];

    public function test_transition_matrix_accepts_only_the_explicit_legal_pairs(): void
    {
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $legal = in_array($to->name, self::EXPECTED_MATRIX[$from->name], true);

                $this->assertSame(
                    $legal,
                    $from->canTransitionTo($to),
                    sprintf('canTransitionTo mismatch for %s -> %s', $from->name, $to->name),
                );
            }
        }
    }

    public function test_illegal_direct_transition_is_rejected(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('getAttribute')->with('status')->andReturn(OrderStatus::CREATED);
        $order->shouldNotReceive('save');

        try {
            OrderTransitions::advance($order, OrderStatus::COMPLETED);
            $this->fail('Expected CREATED -> COMPLETED to be rejected.');
        } catch (IllegalOrderTransitionException $e) {
            $this->assertSame(OrderStatus::CREATED, $e->current);
            $this->assertSame(OrderStatus::COMPLETED, $e->target);
        }
    }

    public function test_legal_transition_advances_and_persists(): void
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('getAttribute')->with('status')->andReturn(OrderStatus::CREATED);
        $order->shouldReceive('setAttribute')->once();
        $order->shouldReceive('save')->once()->andReturn(true);

        $result = OrderTransitions::advance($order, OrderStatus::RESERVED);

        $this->assertSame($order, $result);
        $order->shouldHaveReceived('setAttribute')->once();
        $order->shouldHaveReceived('save')->once();
    }
}
