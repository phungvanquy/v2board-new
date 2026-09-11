<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;

class SubscriptionAndTicketFlowTest extends TestCase
{
    public function testSubscriptionTicketFetchRequiresAuth(): void
    {
        $response = $this->getJson('/api/v1/user/ticket/fetch');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTicketSaveRequiresAuth(): void
    {
        $response = $this->postJson('/api/v1/user/ticket/save', [
            'subject' => 'help',
            'message' => 'need help',
            'level' => 0,
        ]);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTicketReplyRequiresAuth(): void
    {
        $response = $this->postJson('/api/v1/user/ticket/reply', [
            'id' => 1,
            'message' => 'hello',
        ]);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTicketCloseRequiresAuth(): void
    {
        $response = $this->postJson('/api/v1/user/ticket/close', ['id' => 1]);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testClientSubscribeWithoutTokenReturnsError(): void
    {
        $response = $this->getJson('/api/v1/client/subscribe');
        // Should be 400/403/500 (missing token) not 200 with valid subscription
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function testClientSubscribeWithInvalidTokenReturnsError(): void
    {
        $response = $this->getJson('/api/v1/client/subscribe?token=invalid-token-xyz');
        $this->assertTrue(in_array($response->getStatusCode(), [400, 403, 404, 500]));
    }
}
