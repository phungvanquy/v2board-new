<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SubscriptionHelper;
use PHPUnit\Framework\TestCase;

class SubscriptionHelperTest extends TestCase
{
    public function testBase64EncodeUrlSafeRemovesPaddingAndReplacesChars(): void
    {
        // Input that yields + / = in regular base64
        $data = "\xfb\xff\xfe";
        $regular = base64_encode($data);
        $safe = SubscriptionHelper::base64EncodeUrlSafe($data);
        $this->assertStringNotContainsString('+', $safe);
        $this->assertStringNotContainsString('/', $safe);
        $this->assertStringNotContainsString('=', $safe);
        $this->assertSame(str_replace(['+', '/', '='], ['-', '_', ''], $regular), $safe);
    }

    public function testBase64EncodeDecodeRoundtrip(): void
    {
        $original = 'hello world: test+data/with=special';
        $encoded = SubscriptionHelper::base64EncodeUrlSafe($original);
        $decoded = SubscriptionHelper::base64DecodeUrlSafe($encoded);
        $this->assertSame($original, $decoded);
    }

    public function testBase64DecodeUrlSafeHandlesNoPadding(): void
    {
        // base64EncodeUrlSafe strips padding; decode must re-add it.
        $this->assertSame('a', SubscriptionHelper::base64DecodeUrlSafe('YQ'));
        $this->assertSame('ab', SubscriptionHelper::base64DecodeUrlSafe('YWI'));
        $this->assertSame('abc', SubscriptionHelper::base64DecodeUrlSafe('YWJj'));
    }

    public function testEncodeURIComponentKeepsUnreserved(): void
    {
        $input = "!*'() hello world";
        // encodeURIComponent is like rawurlencode but reverts %21 %2A %27 %28 %29
        $expected = strtr(rawurlencode($input), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
        $this->assertSame($expected, SubscriptionHelper::encodeURIComponent($input));
        // Must not encode those five characters
        $this->assertStringContainsString('!', SubscriptionHelper::encodeURIComponent('!'));
        $this->assertStringContainsString("'", SubscriptionHelper::encodeURIComponent("'"));
    }

    public function testEncodeURIComponentEncodesSpaces(): void
    {
        $this->assertSame('a%20b', SubscriptionHelper::encodeURIComponent('a b'));
    }
}
