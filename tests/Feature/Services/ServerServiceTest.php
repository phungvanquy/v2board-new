<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\ServerService;
use PHPUnit\Framework\TestCase as UnitTestCase;

class ServerServiceTest extends UnitTestCase
{
    public function testServiceExposesExpectedMethods(): void
    {
        $svc = new ServerService();
        $this->assertTrue(method_exists($svc, 'getAvailableVmess'));
        $this->assertTrue(method_exists($svc, 'getAvailableVless'));
        $this->assertTrue(method_exists($svc, 'getAvailableTrojan'));
        $this->assertTrue(method_exists($svc, 'getAvailableShadowsocks'));
        $this->assertTrue(method_exists($svc, 'getAvailableHysteria'));
        $this->assertTrue(method_exists($svc, 'getAvailableTuic'));
        $this->assertTrue(method_exists($svc, 'getAvailableAnytls'));
    }

    public function testGetAvailableMethodsReturnArrayType(): void
    {
        // Without DB, these methods would query; we just verify the class contract via reflection.
        $ref = new \ReflectionClass(ServerService::class);
        foreach (['getAvailableVmess', 'getAvailableVless', 'getAvailableTrojan', 'getAvailableShadowsocks'] as $method) {
            $m = $ref->getMethod($method);
            $this->assertSame(1, $m->getNumberOfParameters(), "$method should take one User param");
        }
        // At least the typed ones should declare array return.
        $this->assertSame('array', (string) $ref->getMethod('getAvailableVmess')->getReturnType());
        $this->assertSame('array', (string) $ref->getMethod('getAvailableVless')->getReturnType());
    }
}
