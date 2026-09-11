<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Order;
use App\Services\OrderService;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    public function testStrToTimeMapHasExpectedKeys(): void
    {
        $this->assertArrayHasKey('month_price', OrderService::STR_TO_TIME);
        $this->assertSame(1, OrderService::STR_TO_TIME['month_price']);
        $this->assertSame(12, OrderService::STR_TO_TIME['year_price']);
        $this->assertSame(36, OrderService::STR_TO_TIME['three_year_price']);
    }

    public function testSetOrderTypeDepositIsType9(): void
    {
        $order = new Order();
        $order->period = 'deposit';
        $order->plan_id = 1;
        $svc = new OrderService($order);
        $user = new \App\Models\User();
        $user->plan_id = null;
        $user->expired_at = time() + 3600;
        $svc->setOrderType($user);
        $this->assertSame(9, $order->type);
    }

    public function testSetOrderTypeResetIsType4(): void
    {
        $order = new Order();
        $order->period = 'reset_price';
        $order->plan_id = 1;
        $svc = new OrderService($order);
        $user = new \App\Models\User();
        $user->plan_id = 1;
        $user->expired_at = time() + 3600;
        $svc->setOrderType($user);
        $this->assertSame(4, $order->type);
    }
}
