<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AnyTlsSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AnyTlsSettingsTest extends TestCase
{
    public function testItParsesAdminJsonIntoAStringList(): void
    {
        $this->assertSame(
            ['stop=8', '0=30-30'],
            AnyTlsSettings::fromAdmin('["stop=8", "0=30-30"]')
        );
    }

    public function testItAcceptsAnExistingStringList(): void
    {
        $settings = ['stop=8', '0=30-30'];

        $this->assertSame($settings, AnyTlsSettings::fromAdmin($settings));
    }

    /** @dataProvider invalidPaddingSchemes */
    public function testItRejectsValuesThatV2bxCannotDecode($value): void
    {
        $this->expectException(InvalidArgumentException::class);

        AnyTlsSettings::fromAdmin($value);
    }

    public static function invalidPaddingSchemes(): array
    {
        return [
            ['{"stop": 8}'],
            ['["stop=8", 30]'],
            ['not-json'],
            [123],
        ];
    }

    public function testNodeFallbackReturnsAnEmptyListForLegacyMalformedData(): void
    {
        $this->assertSame([], AnyTlsSettings::forNode(['stop' => 8]));
    }

    public function testNodeOutputPreservesAnUnsetPaddingScheme(): void
    {
        $this->assertNull(AnyTlsSettings::forNode(null));
    }
}
