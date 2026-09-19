<?php

namespace Tests\Feature;

use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\QuantumultX;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\Stash;
use Tests\TestCase;

class ProtocolSnapshotTest extends TestCase
{
    private string $uuid = '8f14e45f-ea51-4ed4-a87c-c79ff1234567';

    private function baseServer(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Node',
            'host' => '1.2.3.4',
            'port' => 443,
            'type' => 'vmess',
            'cipher' => 'aes-128-gcm',
            'network' => 'ws',
            'network_settings' => [
                'path' => '/ws',
                'headers' => ['Host' => 'example.com'],
            ],
            // Stash + some Clash variants read the camelCase key spelling,
            // so provide both to exercise every code path.
            'networkSettings' => [
                'path' => '/ws',
                'headers' => ['Host' => 'example.com'],
            ],
            'tls' => 1,
            'tls_settings' => [
                'allow_insecure' => 0,
                'server_name' => 'example.com',
                'fingerprint' => 'chrome',
            ],
            'tlsSettings' => [
                'allowInsecure' => 0,
                'serverName' => 'example.com',
            ],
            'flow' => '',
            'server_name' => 'example.com',
            'allow_insecure' => 0,
            'congestion_control' => 'bbr',
            'disable_sni' => 0,
            'udp_relay_mode' => 'native',
            'zero_rtt_handshake' => 0,
            'version' => 2,
            'insecure' => 0,
            'down_mbps' => 100,
            'up_mbps' => 100,
            'obfs' => 'salamander',
            'obfs_password' => 'secret',
            'mport' => '443',
            'created_at' => 1700000000,
        ], $overrides);
    }

    public function testClashMetaVmessWsProducesExpectedKeys(): void
    {
        $server = $this->baseServer(['network' => 'ws']);
        $result = ClashMeta::buildVmess($this->uuid, $server);
        $this->assertEquals('vmess', $result['type']);
        $this->assertEquals('ws', $result['network']);
        $this->assertNotEmpty($result['server']);
    }

    public function testClashNyanpasuParityWithClashMeta(): void
    {
        $server = $this->baseServer(['network' => 'ws']);
        $meta = ClashMeta::buildVmess($this->uuid, $server);
        $nyan = ClashNyanpasu::buildVmess($this->uuid, $server);
        $this->assertEquals($meta, $nyan, 'ClashNyanpasu should match ClashMeta for same input');
    }

    public function testClashVergeParityWithClashMeta(): void
    {
        $server = $this->baseServer(['network' => 'grpc', 'network_settings' => ['serviceName' => 'grpc-service'], 'networkSettings' => ['serviceName' => 'grpc-service']]);
        $meta = ClashMeta::buildVmess($this->uuid, $server);
        $verge = ClashVerge::buildVmess($this->uuid, $server);
        $this->assertEquals($meta, $verge);
    }

    public function testStashVmessWsMatchesClashMetaCoreFields(): void
    {
        $server = $this->baseServer(['network' => 'ws']);
        $stash = Stash::buildVmess($this->uuid, $server);
        $meta = ClashMeta::buildVmess($this->uuid, $server);
        // Stash and ClashMeta share core structure for vmess(ws) but Stash
        // also emits ws-path/ws-headers aliases; compare the overlap only.
        foreach (['type', 'name', 'server', 'port', 'uuid', 'network', 'tls'] as $k) {
            $this->assertEquals($meta[$k] ?? null, $stash[$k] ?? null, "vmess(ws) field $k diverged");
        }
    }

    public function testSingboxVmessWsProducesTransport(): void
    {
        $server = $this->baseServer(['network' => 'ws']);
        $user = ['uuid' => $this->uuid, 'u' => 0, 'd' => 0, 'transfer_enable' => 10737418240, 'expired_at' => null];
        $singbox = new Singbox($user, [$server]);
        $ref = new \ReflectionMethod($singbox, 'buildVmess');
        $ref->setAccessible(true);
        $result = $ref->invoke($singbox, $this->uuid, $server);
        $this->assertEquals('vmess', $result['type']);
        $this->assertEquals('ws', $result['transport']['type'] ?? null);
    }

    /** @dataProvider singboxFormatters */
    public function testSingboxRealityWithoutShortIdDoesNotFail(string $formatter): void
    {
        $server = $this->baseServer([
            'type' => 'vless',
            'network' => 'tcp',
            'network_settings' => ['header' => ['type' => 'none']],
            'tls' => 2,
            'tls_settings' => [
                'allow_insecure' => 0,
                'server_name' => 'example.com',
                'public_key' => 'test-public-key',
            ],
        ]);
        $user = ['uuid' => $this->uuid, 'u' => 0, 'd' => 0, 'transfer_enable' => 10737418240, 'expired_at' => null];
        $singbox = new $formatter($user, [$server]);
        $method = new \ReflectionMethod($singbox, 'buildVless');
        $method->setAccessible(true);
        $result = $method->invoke($singbox, $this->uuid, $server);

        $this->assertSame('', $result['tls']['reality']['short_id']);
    }

    public static function singboxFormatters(): array
    {
        return [[Singbox::class], [SingboxOld::class]];
    }

    public function testQuantumultXVmessProducesExpectedOutput(): void
    {
        $server = $this->baseServer(['network' => 'ws']);
        $result = QuantumultX::buildVmess($this->uuid, $server);
        $this->assertStringContainsString('vmess=', $result);
    }

    public function testVlessTcpNyanpasuIsSupersetOfMeta(): void
    {
        $server = $this->baseServer([
            'type' => 'vless',
            'network' => 'tcp',
            'network_settings' => ['header' => ['type' => 'http', 'request' => ['headers' => ['Host' => ['example.com']], 'path' => ['/']]]],
            'networkSettings' => ['header' => ['type' => 'http', 'request' => ['headers' => ['Host' => ['example.com']], 'path' => ['/']]]],
        ]);
        $meta = ClashMeta::buildVless($this->uuid, $server);
        $nyan = ClashNyanpasu::buildVless($this->uuid, $server);
        foreach ($meta as $k => $v) {
            $this->assertEquals($v, $nyan[$k] ?? null, "VLESS(tcp/http) key $k diverged");
        }
    }

    public function testShadowsocksBuild(): void
    {
        $server = $this->baseServer(['type' => 'shadowsocks', 'cipher' => 'aes-256-gcm']);
        $meta = ClashMeta::buildShadowsocks('password123', $server);
        $this->assertEquals('ss', $meta['type']);
        $this->assertEquals('1.2.3.4', $meta['server']);
    }
}
