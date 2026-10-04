<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Tests\Support\ModelBuilder;
use Tests\Support\SubscriptionFixtures;
use Tests\TestCase;

class SubscriptionServerServiceTest extends TestCase
{
    private function available(array $server): array
    {
        $service = Mockery::mock(ServerService::class)->makePartial();
        foreach (['Shadowsocks', 'Vmess', 'Trojan', 'Tuic', 'Hysteria', 'Vless', 'AnyTLS', 'V2node'] as $kind) {
            $service->shouldReceive('getAvailable' . $kind)->with(Mockery::type(User::class))
                ->andReturn(strtolower($kind) === $server['type'] ? [$server] : []);
        }

        return $service->getAvailableServers(ModelBuilder::user())[0];
    }

    /** @dataProvider singlePortProtocols */
    public function testSinglePortProtocolsReceiveAnIntegerFromRangesAndLists(string $protocol, bool $v2node, string $ports, array $allowed): void
    {
        $server = SubscriptionFixtures::server($protocol, $v2node);
        $server['port'] = $ports;
        $result = $this->available($server);
        $this->assertIsInt($result['port']);
        $this->assertContains($result['port'], $allowed);
        $this->assertArrayNotHasKey('mport', $result);
        $uri = Helper::buildUri('test-uuid', $result);
        $this->assertStringNotContainsString($ports, $uri);
    }

    public static function singlePortProtocols(): array
    {
        $cases = [];
        foreach (['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'anytls'] as $protocol) {
            foreach ([false, true] as $v2node) {
                $cases[] = [$protocol, $v2node, '443-445', [443, 444, 445]];
                $cases[] = [$protocol, $v2node, '443,8443', [443, 8443]];
                $cases[] = [$protocol, $v2node, '443-445,8443', [443, 444, 445, 8443]];
            }
        }

        return $cases;
    }

    public function testCommaSeparatedPortsAreNotTruncatedToTheFirstPort(): void
    {
        $server = SubscriptionFixtures::server('vmess', true);
        $server['port'] = '443,8443';
        $selected = [];
        mt_srand(12345);
        try {
            for ($attempt = 0; $attempt < 16; $attempt++) {
                $selected[] = $this->available($server)['port'];
            }
        } finally {
            mt_srand();
        }
        $this->assertContains(443, $selected);
        $this->assertContains(8443, $selected);
    }

    /** @dataProvider hoppingNodes */
    public function testHysteriaKeepsAllPortsInServiceAndUri(bool $v2node, string $ports): void
    {
        $server = SubscriptionFixtures::server($v2node ? 'hysteria2' : 'hysteria', $v2node);
        $server['port'] = $ports;
        $result = $this->available($server);
        $this->assertSame($ports, $result['port']);
        $this->assertSame($ports, $result['mport']);
        $uri = parse_url(trim(Helper::buildUri('test-uuid', $result)));
        parse_str($uri['query'], $query);
        $this->assertSame(443, $uri['port']);
        $this->assertSame($ports, $query['mport']);
    }

    public static function hoppingNodes(): array
    {
        return [[false, '443,8443'], [true, '443,8443'], [false, '443-450,8443'], [true, '443-450,8443']];
    }

    public function testImportedShadowsocksCipherIsDefaultedForEveryFormatter(): void
    {
        $server = SubscriptionFixtures::server('shadowsocks', true);
        $server['cipher'] = null;
        $this->assertSame('aes-128-gcm', $this->available($server)['cipher']);
    }

    /**
     * @dataProvider obfsSettings
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testShadowsocksObfuscationAllowsMissingOptionalSettings(?array $settings, string $host, string $path): void
    {
        $server = SubscriptionFixtures::server('shadowsocks');
        $server['obfs'] = 'http';
        $server['obfs_settings'] = $settings;
        $node = new class () extends Model {
        };
        $node->setRawAttributes($server);
        $query = Mockery::mock();
        $query->shouldReceive('get')->once()->andReturn(new Collection([$node]));
        $model = Mockery::mock('alias:App\\Models\\ServerShadowsocks');
        $model->shouldReceive('orderBy')->with('sort', 'ASC')->once()->andReturn($query);

        $result = (new ServerService())->getAvailableShadowsocks(ModelBuilder::user())[0];
        $this->assertSame($host, $result['obfs-host']);
        $this->assertSame($path, $result['obfs-path']);
        $this->assertStringContainsString('obfs=http', Helper::buildUri('test-uuid', $result));
    }

    public static function obfsSettings(): array
    {
        return [
            [null, '', '/'],
            [[], '', '/'],
            [['host' => 'obfs.example'], 'obfs.example', '/'],
            [['path' => '/custom'], '', '/custom'],
            [['host' => 'obfs.example', 'path' => '/custom'], 'obfs.example', '/custom'],
        ];
    }
}
