<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Protocols\Support\NetworkSettings;
use App\Protocols\Support\UriString;
use PHPUnit\Framework\TestCase;

class UriStringAndNetworkSettingsTest extends TestCase
{
    // UriString

    public function testFormatHostPlain(): void
    {
        $this->assertSame('1.2.3.4', UriString::formatHost('1.2.3.4'));
        $this->assertSame('example.com', UriString::formatHost('example.com'));
    }

    public function testFormatHostIpv6Brackets(): void
    {
        $this->assertSame('[2001:db8::1]', UriString::formatHost('2001:db8::1'));
    }

    public function testBuildUriStringEncodesParams(): void
    {
        $server = ['host' => '1.2.3.4', 'port' => 443];
        $uri = UriString::buildUriString('vmess', 'uuid', $server, 'My Node', ['type' => 'ws']);
        $this->assertStringStartsWith('vmess://uuid@1.2.3.4:443?', $uri);
        $this->assertStringContainsString('type=ws', $uri);
        $this->assertStringEndsWith("#My Node\r\n", $uri);
    }

    public function testBuildUriStringIpv6Host(): void
    {
        $server = ['host' => '2001:db8::1', 'port' => 8443];
        $uri = UriString::buildUriString('vmess', 'uuid', $server, 'n', []);
        $this->assertStringContainsString('[2001:db8::1]:8443', $uri);
    }

    public function testEncodeURIComponent(): void
    {
        $this->assertSame('hello%20world', UriString::encodeURIComponent('hello world'));
        $this->assertStringContainsString('!', UriString::encodeURIComponent('!'));
    }

    public function testBase64UrlSafeEncode(): void
    {
        $encoded = UriString::base64UrlSafeEncode('hello');
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    // NetworkSettings

    public function testForPrefersSnakeCase(): void
    {
        $server = ['network_settings' => ['path' => '/a'], 'networkSettings' => ['path' => '/b']];
        $this->assertSame(['path' => '/a'], NetworkSettings::for($server));
    }

    public function testForFallsBackToCamelCase(): void
    {
        $server = ['networkSettings' => ['path' => '/b']];
        $this->assertSame(['path' => '/b'], NetworkSettings::for($server));
    }

    public function testForEmptyWhenMissing(): void
    {
        $this->assertSame([], NetworkSettings::for([]));
    }

    /** @dataProvider proxyProtocolValues */
    public function testNormalizeForNodeUsesBooleanProxyProtocol($value, bool $expected): void
    {
        $settings = NetworkSettings::normalizeForNode([
            'acceptProxyProtocol' => $value,
            'header' => ['type' => 'none'],
        ]);

        $this->assertSame($expected, $settings['acceptProxyProtocol']);
        $this->assertSame(['type' => 'none'], $settings['header']);
    }

    public static function proxyProtocolValues(): array
    {
        return [
            [true, true],
            [false, false],
            ['true', true],
            ['false', false],
            ['1', true],
            ['0', false],
            [1, true],
            [0, false],
        ];
    }

    public function testNormalizeForNodeLeavesAbsentProxyProtocolAbsent(): void
    {
        $settings = ['header' => ['type' => 'none']];

        $this->assertSame($settings, NetworkSettings::normalizeForNode($settings));
    }

    public function testNormalizeForNodeUsesStrictV2bxTransportTypes(): void
    {
        $settings = NetworkSettings::normalizeForNode([
            'multiMode' => '1',
            'permit_without_stream' => 'false',
            'heartbeatPeriod' => '30',
            'idle_timeout' => '45',
            'extra' => [
                'xPaddingObfsMode' => 'true',
                'noGRPCHeader' => '0',
                'noSSEHeader' => '1',
                'scMaxBufferedPosts' => '8',
                'serverMaxHeaderBytes' => '4096',
                'xmux' => ['hKeepAlivePeriod' => '15'],
                'downloadSettings' => ['port' => '8443'],
            ],
        ]);

        $this->assertSame(true, $settings['multiMode']);
        $this->assertSame(false, $settings['permit_without_stream']);
        $this->assertSame(30, $settings['heartbeatPeriod']);
        $this->assertSame(45, $settings['idle_timeout']);
        $this->assertSame(true, $settings['extra']['xPaddingObfsMode']);
        $this->assertSame(false, $settings['extra']['noGRPCHeader']);
        $this->assertSame(true, $settings['extra']['noSSEHeader']);
        $this->assertSame(8, $settings['extra']['scMaxBufferedPosts']);
        $this->assertSame(4096, $settings['extra']['serverMaxHeaderBytes']);
        $this->assertSame(15, $settings['extra']['xmux']['hKeepAlivePeriod']);
        $this->assertSame(8443, $settings['extra']['downloadSettings']['port']);
    }

    public function testTlsPrefersSnakeCase(): void
    {
        $server = ['tls_settings' => ['server_name' => 'a'], 'tlsSettings' => ['serverName' => 'b']];
        $this->assertSame(['server_name' => 'a'], NetworkSettings::tls($server));
    }

    public function testApplyWs(): void
    {
        $server = ['network' => 'ws', 'network_settings' => ['path' => '/ws', 'headers' => ['Host' => 'example.com']]];
        $config = [];
        NetworkSettings::apply($server, $config);
        $this->assertSame('/ws', $config['path']);
        $this->assertSame('example.com', $config['host']);
    }

    public function testApplyGrpc(): void
    {
        $server = ['network' => 'grpc', 'network_settings' => ['serviceName' => 'myService']];
        $config = [];
        NetworkSettings::apply($server, $config);
        $this->assertSame('myService', $config['serviceName']);
    }

    public function testApplyUnknownNetworkIsNoop(): void
    {
        $server = ['network' => 'unknown', 'network_settings' => ['path' => '/x']];
        $config = ['existing' => 1];
        NetworkSettings::apply($server, $config);
        $this->assertSame(['existing' => 1], $config);
    }

    public function testConfigureTcpHttpHeader(): void
    {
        $settings = ['header' => ['type' => 'http', 'request' => ['headers' => ['Host' => ['example.com']], 'path' => ['/']]]];
        $config = [];
        NetworkSettings::configureTcpSettings($settings, $config);
        $this->assertSame('http', $config['headerType']);
        $this->assertSame('example.com', $config['host']);
        $this->assertSame('/', $config['path']);
    }

    public function testConfigureKcpDefaults(): void
    {
        $config = [];
        NetworkSettings::configureKcpSettings([], $config);
        $this->assertSame('none', $config['headerType']);
    }

    public function testConfigureXhttpWithExtras(): void
    {
        $settings = ['path' => '/x', 'host' => 'h', 'mode' => 'stream', 'extra' => ['a' => 1]];
        $config = [];
        NetworkSettings::configureXhttpSettings($settings, $config);
        $this->assertSame('/x', $config['path']);
        $this->assertSame('stream', $config['mode']);
        $this->assertJson($config['extra']);
    }
}
