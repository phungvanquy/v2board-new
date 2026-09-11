<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\Shadowsocks;
use PHPUnit\Framework\TestCase;

class ExtraProtocolSnapshotTest extends TestCase
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
            'networkSettings' => [
                'path' => '/ws',
                'headers' => ['Host' => 'example.com'],
            ],
            'tls' => 1,
            'tls_settings' => [
                'allow_insecure' => 0,
                'server_name' => 'example.com',
            ],
            'tlsSettings' => [
                'allowInsecure' => 0,
                'serverName' => 'example.com',
            ],
            'server_name' => 'example.com',
            'allow_insecure' => 0,
            'cipher' => 'aes-128-gcm',
            'id' => 1,
        ], $overrides);
    }

    public function testClashBuildShadowsocks(): void
    {
        $server = $this->baseServer(['type' => 'shadowsocks', 'cipher' => 'aes-128-gcm']);
        $result = Clash::buildShadowsocks($this->uuid, $server);
        $this->assertSame('ss', $result['type']);
        $this->assertSame('1.2.3.4', $result['server']);
    }

    public function testClashBuildVmessWs(): void
    {
        $server = $this->baseServer(['network' => 'ws']);
        $result = Clash::buildVmess($this->uuid, $server);
        $this->assertSame('vmess', $result['type']);
        $this->assertSame('ws', $result['network']);
    }

    public function testClashBuildVmessGrpc(): void
    {
        $server = $this->baseServer([
            'network' => 'grpc',
            'network_settings' => ['serviceName' => 'mySvc'],
            'networkSettings' => ['serviceName' => 'mySvc'],
        ]);
        $result = Clash::buildVmess($this->uuid, $server);
        $this->assertSame('grpc', $result['network']);
        $this->assertSame('mySvc', $result['grpc-opts']['grpc-service-name']);
    }

    public function testClashBuildTrojan(): void
    {
        $server = $this->baseServer(['type' => 'trojan', 'network' => 'ws', 'network_settings' => ['path' => '/ws', 'headers' => ['Host' => 'example.com']]]);
        $result = Clash::buildTrojan('password123', $server);
        $this->assertSame('trojan', $result['type']);
        $this->assertSame('password123', $result['password']);
    }

    public function testShadowsocksSip008(): void
    {
        $server = $this->baseServer(['type' => 'shadowsocks']);
        $result = Shadowsocks::SIP008($server, ['uuid' => $this->uuid]);
        $this->assertSame('1.2.3.4', $result['server']);
        $this->assertSame($this->uuid, $result['password']);
    }

    public function testClashMetaVmessTcpHttp(): void
    {
        $server = $this->baseServer([
            'network' => 'tcp',
            'network_settings' => [
                'header' => [
                    'type' => 'http',
                    'request' => [
                        'headers' => ['Host' => ['example.com']],
                        'path' => ['/path'],
                    ],
                ],
            ],
            'networkSettings' => [
                'header' => [
                    'type' => 'http',
                    'request' => [
                        'headers' => ['Host' => ['example.com']],
                        'path' => ['/path'],
                    ],
                ],
            ],
        ]);
        $result = ClashMeta::buildVmess($this->uuid, $server);
        $this->assertSame('vmess', $result['type']);
    }

    public function testClashMetaAllMethodsExist(): void
    {
        $this->assertTrue(method_exists(ClashMeta::class, 'buildVmess'));
        $this->assertTrue(method_exists(ClashMeta::class, 'buildVless'));
        $this->assertTrue(method_exists(ClashMeta::class, 'buildTrojan'));
        $this->assertTrue(method_exists(ClashMeta::class, 'buildShadowsocks'));
    }
}
