<?php

declare(strict_types=1);

namespace Tests\Unit\Utils;

use App\Utils\CacheKey;
use PHPUnit\Framework\TestCase;

class CacheKeyTest extends TestCase
{
    public function testAllowedKeyReturnsComposite(): void
    {
        $result = CacheKey::get('EMAIL_VERIFY_CODE', 'user@example.com');
        $this->assertSame('EMAIL_VERIFY_CODE_user@example.com', $result);
    }

    public function testAllowedKeyWithNumericSuffix(): void
    {
        $result = CacheKey::get('TEMP_TOKEN', 12345);
        $this->assertSame('TEMP_TOKEN_12345', $result);
    }

    public function testDisallowedKeyThrows(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        CacheKey::get('NOT_A_REAL_KEY', 'value');
    }

    public function testAllDefinedKeysAreAccepted(): void
    {
        foreach (array_keys(CacheKey::KEYS) as $key) {
            $result = CacheKey::get($key, 'x');
            $this->assertSame($key . '_x', $result);
        }
    }
}
