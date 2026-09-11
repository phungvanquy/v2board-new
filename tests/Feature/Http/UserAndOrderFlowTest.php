<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

class UserAndOrderFlowTest extends TestCase
{
    public function testUnauthenticatedOrderSaveReturns403(): void
    {
        $response = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => 1,
            'period' => 'month_price',
        ]);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedOrderFetchReturns403(): void
    {
        $response = $this->getJson('/api/v1/user/order/fetch');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedGetSubscribeReturns403(): void
    {
        $response = $this->getJson('/api/v1/user/getSubscribe');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedServerFetchReturns403(): void
    {
        $response = $this->getJson('/api/v1/user/server/fetch');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedTicketFetchReturns403(): void
    {
        $response = $this->getJson('/api/v1/user/ticket/fetch');
        $this->assertSame(403, $response->getStatusCode());
    }
}
