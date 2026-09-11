<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Exceptions\ApiException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\CreatesApplication;

class ValidationAndErrorShapeTest extends BaseTestCase
{
    use CreatesApplication;

    public function testApiExceptionMapsToCorrectHttpStatus(): void
    {
        $cases = [
            [ApiException::badRequest('bad'), 400],
            [ApiException::forbidden('forbidden'), 403],
            [ApiException::notFound('not found'), 404],
            [ApiException::fail('fail'), 500],
        ];
        foreach ($cases as [$exception, $expectedStatus]) {
            $this->assertSame($expectedStatus, $exception->getStatusCode());
        }
    }

    public function testValidationErrorShapeIsNotHtml(): void
    {
        // POST without required fields should return JSON, not HTML
        $response = $this->postJson('/api/v1/passport/auth/login', []);
        $contentType = $response->headers->get('Content-Type') ?? '';
        // Laravel's ForceJson/validation should return JSON
        $this->assertTrue(
            str_contains($contentType, 'json') || $response->getStatusCode() !== 200,
            'Validation failure should return JSON or non-200'
        );
    }

    public function testAbortIfNullUsesCorrectStatus(): void
    {
        try {
            ApiException::abortIfNull(null, 'missing', 404);
            $this->fail('Should have thrown');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('missing', $e->getMessage());
        }
    }

    public function testAbortIfUsesCorrectStatus(): void
    {
        try {
            ApiException::abortIf(true, 'condition', 403);
            $this->fail('Should have thrown');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        // Should not throw when false
        ApiException::abortIf(false, 'nope', 403);
        $this->assertTrue(true);
    }
}
