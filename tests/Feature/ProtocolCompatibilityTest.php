<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\V1\Client\ClientController;
use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\Loon;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Stash;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Tests\Support\ModelBuilder;
use Tests\Support\SubscriptionFixtures;
use Tests\TestCase;

class ProtocolCompatibilityTest extends TestCase
{
    /** @dataProvider vmessFormatters */
    public function testVmessPreservesTlsAndWebsocketSettings(string $formatter, bool $v2node): void
    {
        $server = SubscriptionFixtures::server('vmess', $v2node);
        $result = $formatter::buildVmess('test-uuid', $server);
        if (is_array($result)) {
            $this->assertSame('sni.example.com', $result['servername']);
            $this->assertTrue($result['skip-cert-verify']);
            $this->assertSame('/test-ws', $result['ws-opts']['path']);
            $this->assertSame('ws.example.com', $result['ws-opts']['headers']['Host']);
        } else {
            $this->assertStringContainsString('sni.example.com', $result);
            $this->assertStringContainsString('skip-cert-verify=true', $result);
            $this->assertStringContainsString('/test-ws', $result);
            $this->assertStringContainsString('ws.example.com', $result);
        }
    }

    /** @dataProvider vmessFormatters */
    public function testVmessAllowsNullOptionalSettings(string $formatter, bool $v2node): void
    {
        $server = SubscriptionFixtures::server('vmess', $v2node);
        $server[$v2node ? 'tls_settings' : 'tlsSettings'] = null;
        $server[$v2node ? 'network_settings' : 'networkSettings'] = null;
        foreach (['tcp', 'ws'] as $network) {
            $server['network'] = $network;
            $result = $formatter::buildVmess('test-uuid', $server);
            $this->assertStringContainsString('192.0.2.1', is_array($result) ? json_encode($result) : $result);
        }
    }

    public static function vmessFormatters(): array
    {
        $cases = [];
        foreach ([ClashMeta::class, ClashNyanpasu::class, ClashVerge::class, Stash::class, Loon::class, Surge::class, Surfboard::class] as $formatter) {
            foreach ([false, true] as $v2node) {
                $cases[] = [$formatter, $v2node];
            }
        }

        return $cases;
    }

    /** @dataProvider trojanFormatters */
    public function testTrojanPreservesTlsSettings(string $formatter, bool $v2node): void
    {
        $server = SubscriptionFixtures::server('trojan', $v2node);
        $result = $formatter::buildTrojan('test-password', $server);
        if (is_array($result)) {
            $this->assertSame('sni.example.com', $result['sni']);
            $this->assertTrue($result['skip-cert-verify']);
        } else {
            $this->assertStringContainsString('sni=sni.example.com', $result);
            $this->assertStringContainsString('skip-cert-verify=true', $result);
        }
    }

    public static function trojanFormatters(): array
    {
        $cases = [];
        foreach ([Stash::class, Surge::class, Surfboard::class] as $formatter) {
            foreach ([false, true] as $v2node) {
                $cases[] = [$formatter, $v2node];
            }
        }

        return $cases;
    }

    /** @dataProvider nodeStorageTypes */
    public function testStashTuicAndAnyTlsPreserveTlsSettings(bool $v2node): void
    {
        foreach (['tuic' => 'buildTuic', 'anytls' => 'buildAnyTLS'] as $protocol => $method) {
            $result = Stash::$method('test-password', SubscriptionFixtures::server($protocol, $v2node));
            $this->assertSame('sni.example.com', $result['sni']);
            $this->assertTrue($result['skip-cert-verify']);
        }
    }

    public static function nodeStorageTypes(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider nodeStorageTypes */
    public function testHysteria2PreservesTlsSettingsInLoonAndSurge(bool $v2node): void
    {
        $server = SubscriptionFixtures::server($v2node ? 'hysteria2' : 'hysteria', $v2node);
        foreach ([Loon::class, Surge::class] as $formatter) {
            $result = $formatter::buildHysteria('test-password', $server);
            $this->assertStringContainsString('sni=sni.example.com', $result);
            $this->assertStringContainsString('skip-cert-verify=true', $result);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSubscriptionEndpointRendersAllPreviouslyFailingNodeFormats(): void
    {
        config(['v2board.app_name' => 'Compatibility test', 'v2board.subscribe_url' => 'https://panel.example',
            'v2board.show_subscribe_method' => 0, 'v2board.show_info_to_server_enable' => 0]);
        $_SERVER['HTTP_HOST'] = 'panel.example';
        $user = ModelBuilder::user();
        $method = new \ReflectionMethod(ClientController::class, 'renderSubscription');
        $method->setAccessible(true);
        foreach ([false, true] as $v2node) {
            foreach ([
                'anytls' => ['stash', 'sing-box/1.12.0'],
                'vmess' => ['loon', 'stash', 'surge', 'surfboard', 'clashmeta', 'clashverge', 'clashnyanpasu'],
                'trojan' => ['surge', 'surfboard', 'stash'],
                'tuic' => ['stash'],
                'hysteria2' => ['loon', 'surge'],
                'shadowsocks' => ['shadowsocks'],
            ] as $protocol => $flags) {
                $server = SubscriptionFixtures::server($protocol === 'hysteria2' && !$v2node ? 'hysteria' : $protocol, $v2node);
                foreach ($flags as $flag) {
                    $request = Request::create('/api/v1/client/subscribe', 'GET', ['flag' => $flag]);
                    $response = $method->invoke(new ClientController(), $request, $user, [$server]);
                    $this->assertSame(200, $response->getStatusCode(), "$protocol / $flag");
                    $this->assertStringContainsString('Compatibility node', $response->getContent(), "$protocol / $flag");
                    $this->assertNotEmpty($response->headers->get('subscription-userinfo'));
                    if ($flag === 'sing-box/1.12.0') {
                        $config = json_decode($response->getContent(), true);
                        $nodes = array_values(array_filter($config['outbounds'], fn ($node) => ($node['tag'] ?? '') === 'Compatibility node'));
                        $this->assertTrue($nodes[0]['tls']['enabled']);
                        $this->assertSame('sni.example.com', $nodes[0]['tls']['server_name']);
                    }
                }
            }
        }
    }

    public function testImportedShadowsocksWithoutCipherUsesTheSaveDefault(): void
    {
        $server = SubscriptionFixtures::server('shadowsocks', true);
        $server['cipher'] = null;
        $uri = parse_url(trim(Helper::buildUri('test-password', $server)));
        $this->assertSame('aes-128-gcm:test-password', base64_decode($uri['user']));
    }

    /** @dataProvider hoppingPorts */
    public function testModernSingboxPreservesHysteriaPortLists(bool $v2node, int $version, $port, array $expected): void
    {
        $server = SubscriptionFixtures::server($v2node ? 'hysteria2' : 'hysteria', $v2node);
        if (!$v2node) {
            $server['version'] = $version;
        }
        $server['port'] = $port;
        $formatter = new Singbox(ModelBuilder::user(), [$server]);
        $config = json_decode($formatter->handle()->getContent(), true);
        $nodes = array_values(array_filter($config['outbounds'], fn ($node) => ($node['tag'] ?? '') === 'Compatibility node'));
        $this->assertSame($expected, array_intersect_key($nodes[0], array_flip(['server_port', 'server_ports'])));
    }

    public static function hoppingPorts(): array
    {
        $cases = [];
        foreach ([[false, 1], [false, 2], [true, 2]] as [$v2node, $version]) {
            $cases[] = [$v2node, $version, 443, ['server_port' => 443]];
            $cases[] = [$v2node, $version, '443,8443', ['server_ports' => ['443:443', '8443:8443']]];
            $cases[] = [$v2node, $version, '443-450,8443', ['server_ports' => ['443:450', '8443:8443']]];
        }

        return $cases;
    }
}
