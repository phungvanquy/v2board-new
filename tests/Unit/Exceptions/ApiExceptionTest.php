<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

class ApiExceptionTest extends TestCase
{
    public function testFailDefaultsTo500(): void
    {
        $e = ApiException::fail('oops');
        $this->assertSame(500, $e->getStatusCode());
        $this->assertSame('oops', $e->getMessage());
    }

    public function testFailWithCustomStatus(): void
    {
        $e = ApiException::fail('nope', 422);
        $this->assertSame(422, $e->getStatusCode());
    }

    public function testBadRequestIs400(): void
    {
        $e = ApiException::badRequest('bad');
        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame('bad', $e->getMessage());
    }

    public function testForbiddenIs403(): void
    {
        $this->assertSame(403, ApiException::forbidden()->getStatusCode());
    }

    public function testNotFoundIs404(): void
    {
        $this->assertSame(404, ApiException::notFound()->getStatusCode());
    }

    public function testAbortIfThrowsWhenTrue(): void
    {
        $this->expectException(ApiException::class);
        ApiException::abortIf(true, 'yes', 400);
    }

    public function testAbortIfDoesNotThrowWhenFalse(): void
    {
        ApiException::abortIf(false, 'no');
        $this->assertTrue(true);
    }

    public function testAbortIfNullThrowsWhenNull(): void
    {
        $this->expectException(ApiException::class);
        ApiException::abortIfNull(null, 'null');
    }

    public function testAbortIfNullDoesNotThrowWhenNotNull(): void
    {
        ApiException::abortIfNull(0, 'zero');
        ApiException::abortIfNull('', 'empty');
        $this->assertTrue(true);
    }

    public function testApiExceptionIsHttpException(): void
    {
        $this->assertInstanceOf(\Symfony\Component\HttpKernel\Exception\HttpException::class, ApiException::fail());
    }
}
