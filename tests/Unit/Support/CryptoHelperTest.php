<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CryptoHelper;
use PHPUnit\Framework\TestCase;

class CryptoHelperTest extends TestCase
{
    public function testGuidDefaultReturnsMd5Length(): void
    {
        $guid = CryptoHelper::guid();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $guid);
    }

    public function testGuidFormattedReturnsUuid(): void
    {
        $guid = CryptoHelper::guid(true);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
            $guid
        );
    }

    public function testGuidUnique(): void
    {
        $this->assertNotSame(CryptoHelper::guid(), CryptoHelper::guid());
    }

    public function testUuidToBase64(): void
    {
        $uuid = '8f14e45f-ea51-4ed4-a87c-c79ff1234567';
        $result = CryptoHelper::uuidToBase64($uuid, 8);
        $this->assertSame(base64_encode(substr($uuid, 0, 8)), $result);
    }

    public function testGetServerKeyDeterministic(): void
    {
        $key = CryptoHelper::getServerKey(1234567890, 8);
        $expected = base64_encode(substr(md5('1234567890'), 0, 8));
        $this->assertSame($expected, $key);
    }

    public function testRandomCharLengthAndCharset(): void
    {
        $str = CryptoHelper::randomChar(16);
        $this->assertSame(16, strlen($str));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $str);
    }

    public function testRandomCharWithSpecial(): void
    {
        // Just verify length; charset includes symbols so exact set varies.
        $str = CryptoHelper::randomChar(32, true);
        $this->assertSame(32, strlen($str));
    }

    public function testRandomPortWithinRange(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $port = CryptoHelper::randomPort('10000-20000');
            $this->assertGreaterThanOrEqual(10000, $port);
            $this->assertLessThanOrEqual(20000, $port);
        }
    }

    public function testGenerateEchKeyPairShape(): void
    {
        $pair = CryptoHelper::generateEchKeyPair('example.com');
        $this->assertArrayHasKey('ech_key', $pair);
        $this->assertArrayHasKey('ech_config', $pair);
        // Both are base64 strings
        $this->assertNotEmpty(base64_decode($pair['ech_key'], true));
        $this->assertNotEmpty(base64_decode($pair['ech_config'], true));
    }

    public function testGenerateEchKeyPairDifferentEachCall(): void
    {
        $a = CryptoHelper::generateEchKeyPair('example.com');
        $b = CryptoHelper::generateEchKeyPair('example.com');
        $this->assertNotSame($a['ech_key'], $b['ech_key']);
    }
}
