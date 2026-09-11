<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\ModelBuilder;

class ModelBuilderTest extends TestCase
{
    public function testUserBuilderReturnsUnsavedUserWithDefaults(): void
    {
        $user = ModelBuilder::user();
        $this->assertInstanceOf(User::class, $user);
        $this->assertFalse($user->exists);
        $this->assertStringContainsString('@example.com', (string) $user->email);
        $this->assertNotEmpty($user->password);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', (string) $user->uuid);
        $this->assertNotEmpty($user->token);
        $this->assertSame(0, (int) $user->banned);
        $this->assertSame(0, (int) $user->is_admin);
    }

    public function testUserBuilderAppliesOverrides(): void
    {
        $user = ModelBuilder::user([
            'email' => 'custom@example.com',
            'is_admin' => 1,
            'balance' => 5000,
            'expired_at' => null,
        ]);
        $this->assertSame('custom@example.com', $user->email);
        $this->assertSame(1, (int) $user->is_admin);
        $this->assertSame(5000, (int) $user->balance);
        $this->assertNull($user->expired_at);
    }

    public function testUserBuilderEmailsAreUnique(): void
    {
        $this->assertNotSame(ModelBuilder::user()->email, ModelBuilder::user()->email);
    }

    public function testPlanBuilderReturnsUnsavedPlanWithDefaults(): void
    {
        $plan = ModelBuilder::plan();
        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertFalse($plan->exists);
        $this->assertSame('Test Plan', $plan->name);
        $this->assertSame(1000, (int) $plan->month_price);
    }

    public function testPlanBuilderAppliesOverrides(): void
    {
        $plan = ModelBuilder::plan(['name' => 'Premium', 'year_price' => 9900, 'reset_traffic_method' => 1]);
        $this->assertSame('Premium', $plan->name);
        $this->assertSame(9900, (int) $plan->year_price);
        $this->assertSame(1, (int) $plan->reset_traffic_method);
    }

    public function testOrderBuilderReturnsUnsavedOrderWithDefaults(): void
    {
        $order = ModelBuilder::order();
        $this->assertInstanceOf(Order::class, $order);
        $this->assertFalse($order->exists);
        $this->assertSame('month_price', $order->period);
        $this->assertSame(1000, (int) $order->total_amount);
        $this->assertSame(0, (int) $order->status);
        $this->assertNotEmpty($order->trade_no);
    }

    public function testOrderBuilderAppliesOverrides(): void
    {
        $order = ModelBuilder::order([
            'user_id' => 42,
            'plan_id' => 7,
            'period' => 'year_price',
            'status' => 3,
        ]);
        $this->assertSame(42, (int) $order->user_id);
        $this->assertSame(7, (int) $order->plan_id);
        $this->assertSame('year_price', $order->period);
        $this->assertSame(3, (int) $order->status);
    }

    public function testOrderTradeNosAreUnique(): void
    {
        $this->assertNotSame(ModelBuilder::order()->trade_no, ModelBuilder::order()->trade_no);
    }
}
