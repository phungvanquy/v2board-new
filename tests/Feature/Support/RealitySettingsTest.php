<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\RealitySettings;
use Tests\TestCase;

class RealitySettingsTest extends TestCase
{
    public function testBlankValuesAreGenerated(): void
    {
        $settings = RealitySettings::withDefaults([
            'public_key' => '',
            'private_key' => '',
            'short_id' => '',
            'server_port' => '',
        ]);

        $this->assertNotEmpty($settings['public_key']);
        $this->assertNotEmpty($settings['private_key']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $settings['short_id']);
        $this->assertSame(substr(sha1($settings['private_key']), 0, 8), $settings['short_id']);
        $this->assertSame('443', $settings['server_port']);
    }

    public function testNonEmptyCustomValuesArePreserved(): void
    {
        $settings = [
            'public_key' => 'custom-public-key',
            'private_key' => 'custom-private-key',
            'short_id' => 'a1b2c3d4',
            'server_port' => '8443',
        ];

        $this->assertSame($settings, RealitySettings::withDefaults($settings));
    }

    public function testItUsesStringValuesRequiredByV2bxRealityDecoder(): void
    {
        $settings = RealitySettings::forNode([
            'server_port' => 443,
            'xver' => 2,
            'short_id' => 12345678,
        ]);

        $this->assertSame('443', $settings['server_port']);
        $this->assertSame('2', $settings['xver']);
        $this->assertSame('12345678', $settings['short_id']);
    }

    public function testItClampsRealityProxyProtocolVersion(): void
    {
        $this->assertSame('0', RealitySettings::forNode(['xver' => -1])['xver']);
        $this->assertSame('2', RealitySettings::forNode(['xver' => 99])['xver']);
    }
}
