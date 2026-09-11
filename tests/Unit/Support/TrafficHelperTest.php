<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TrafficHelper;
use PHPUnit\Framework\TestCase;

class TrafficHelperTest extends TestCase
{
    public function testZeroBytes(): void
    {
        $this->assertSame('0 B', TrafficHelper::trafficConvert(0));
    }

    public function testNegativeReturnsZeroInt(): void
    {
        $this->assertSame(0, TrafficHelper::trafficConvert(-1));
        $this->assertSame(0, TrafficHelper::trafficConvert(-9999));
    }

    public function testBytesRange(): void
    {
        $this->assertSame('512 B', TrafficHelper::trafficConvert(512));
        $this->assertSame('1024 B', TrafficHelper::trafficConvert(1024));
    }

    public function testKilobytes(): void
    {
        // 1500 > 1024 so KB
        $this->assertStringEndsWith(' KB', TrafficHelper::trafficConvert(1500));
        $this->assertSame('2 KB', TrafficHelper::trafficConvert(2048));
    }

    public function testMegabytes(): void
    {
        $twoMb = 2 * 1024 * 1024;
        $this->assertStringEndsWith(' MB', TrafficHelper::trafficConvert($twoMb));
    }

    public function testGigabytes(): void
    {
        $twoGb = 2 * 1024 * 1024 * 1024;
        $result = TrafficHelper::trafficConvert($twoGb);
        $this->assertStringEndsWith(' GB', $result);
    }

    public function testBoundaryAtExactlyOneMbIsStillKbBranch(): void
    {
        // implementation uses > not >=, so exactly 1 MB falls to KB branch
        $oneMb = 1048576;
        $result = TrafficHelper::trafficConvert($oneMb);
        $this->assertStringEndsWith(' KB', $result);
    }
}
