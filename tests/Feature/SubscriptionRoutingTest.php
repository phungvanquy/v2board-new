<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\V1\Client\ClientController;
use App\Support\SubscriptionRuleService;
use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscriptionRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'v2board.app_name' => 'Routing Test',
            'v2board.subscribe_ru_direct_enable' => 1,
            'v2board.subscribe_ru_direct_domains' => '2ip.io',
            'v2board.show_info_to_server_enable' => 0,
        ]);
    }

    private function render(string $agent, ?string $flag = null)
    {
        $request = Request::create('/api/v1/client/subscribe', 'GET', $flag === null ? [] : ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $agent]);
        $user = ['uuid' => '8f14e45f-ea51-4ed4-a87c-c79ff1234567', 'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => null];
        $servers = [['name' => 'Test node', 'type' => 'shadowsocks', 'host' => '192.0.2.1', 'port' => 443, 'cipher' => 'aes-128-gcm']];
        $method = new \ReflectionMethod(ClientController::class, 'renderSubscription');
        $method->setAccessible(true);

        return $method->invoke(new ClientController(), $request, $user, $servers);
    }

    public function testHappReceivesNodesAndAnActivatedRoutingProfile(): void
    {
        // App identity wins over compatibility names in a composite User-Agent.
        $response = $this->render('Happ/3.0.0 (iOS) like sing-box');
        $body = base64_decode($response->getContent(), true);
        $this->assertStringContainsString('ss://', $body);
        $profileLink = explode("\n", $body)[0];
        $this->assertStringStartsWith('happ://routing/onadd/', $profileLink);
        $profile = json_decode(base64_decode(substr($profileLink, strlen('happ://routing/onadd/'))), true);
        $this->assertContains('domain:2ip.io', $profile['DirectSites']);
        $this->assertContains('domain:ru', $profile['DirectSites']);
        $this->assertContains('geoip:ru', $profile['DirectIp']);
        $this->assertSame('true', $profile['GlobalProxy']);
        $this->assertStringContainsString('expire=0', $response->headers->get('subscription-userinfo'));
    }

    public function testHappDisabledRulesReplaceTheExistingProfile(): void
    {
        $enabled = SubscriptionRuleService::happRoutingLink();
        config(['v2board.subscribe_ru_direct_enable' => 0]);
        $disabled = SubscriptionRuleService::happRoutingLink();
        $prefixLength = strlen('happ://routing/onadd/');
        $old = json_decode(base64_decode(substr($enabled, $prefixLength)), true);
        $new = json_decode(base64_decode(substr($disabled, $prefixLength)), true);
        $this->assertSame($old['Name'], $new['Name']);
        $this->assertSame([], $new['DirectSites']);
        $this->assertNotContains('geoip:ru', $new['DirectIp']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testKaringReceivesClashRulesBeforeProxyRules(): void
    {
        $response = $this->render('Karing/1.2.0; ClashMeta; sing-box/1.12.0');
        $config = Yaml::parse($response->getContent());
        $this->assertNotEmpty($config['proxies']);
        $this->assertContains('DOMAIN-SUFFIX,2ip.io,DIRECT', $config['rules']);
        $directIndex = array_search('DOMAIN-SUFFIX,2ip.io,DIRECT', $config['rules'], true);
        foreach ($config['rules'] as $i => $rule) {
            if (strpos($rule, 'MATCH,') === 0) {
                $this->assertLessThan($i, $directIndex);
            }
        }
    }

    /** @dataProvider singboxAgents */
    public function testSingboxFormatsIncludeSuffixesInRoutingAndDns(string $agent, bool $modern): void
    {
        $config = json_decode($this->render($agent)->getContent(), true);
        $this->assertIsArray($config);
        $route = array_values(array_filter($config['route']['rules'], fn ($rule) => in_array('2ip.io', $rule['domain_suffix'] ?? [], true)))[0];
        $dns = array_values(array_filter($config['dns']['rules'], fn ($rule) => in_array('2ip.io', $rule['domain_suffix'] ?? [], true)))[0];
        $this->assertSame(SubscriptionRuleService::suffixes(), $route['domain_suffix']);
        $this->assertSame($route['domain_suffix'], $dns['domain_suffix']);
        $this->assertSame('local', $dns['server']);
        $this->assertSame($modern ? 'DIRECT' : 'direct', $route['outbound']);
        $this->assertSame($modern ? 'route' : null, $route['action'] ?? null);
    }

    public static function singboxAgents(): array
    {
        return [
            ['Hiddify/2.5.7', false],
            ['HiddifyNext/2.5.7 (android) like ClashMeta v2ray sing-box', false],
            ['HiddifyNext/4.1.1 (android) like ClashMeta v2ray sing-box', false],
            ['HiddifyNext/4.0.0 sing-box/1.12.0', true],
            ['sing-box 1.12.0', true],
            ['sing-box/1.12.1', true],
            ['sing-box/1.11.15', false],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testExplicitFormatWinsAndUnknownAppsKeepNodeSubscriptions(): void
    {
        $this->assertArrayHasKey('proxies', Yaml::parse($this->render('Happ/3.0.0', 'clashmeta')->getContent()));
        $this->assertStringStartsWith('happ://routing/onadd/', base64_decode($this->render('unknown', 'happ')->getContent()));
        $this->assertStringStartsWith('ss://', base64_decode($this->render('unknown')->getContent()));
        $this->assertStringStartsWith('ss://', base64_decode($this->render('Karing/1.2.0', 'general')->getContent()));
    }

    /** @dataProvider schemas */
    public function testSingboxBypassPrecedesCatchAllAndInjectionIsIdempotent(bool $legacy): void
    {
        $config = [
            'outbounds' => [['type' => 'direct', 'tag' => 'direct'], ['type' => 'selector', 'tag' => 'proxy']],
            'route' => ['rules' => [
                ['action' => 'sniff'],
                ['protocol' => 'dns', 'action' => 'hijack-dns'],
                ['clash_mode' => 'Global', 'outbound' => 'proxy'],
                ['outbound' => 'proxy'],
            ]],
            'dns' => ['rules' => [['server' => 'remote']]],
        ];
        $injected = SubscriptionRuleService::injectSingbox($config, $legacy);
        $this->assertSame('sniff', $injected['route']['rules'][0]['action']);
        $this->assertSame('hijack-dns', $injected['route']['rules'][1]['action']);
        $this->assertSame('Global', $injected['route']['rules'][2]['clash_mode']);
        $this->assertContains('2ip.io', $injected['route']['rules'][3]['domain_suffix']);
        $this->assertSame('direct', $injected['route']['rules'][3]['outbound']);
        $this->assertContains('2ip.io', $injected['dns']['rules'][0]['domain_suffix']);
        $this->assertSame($injected, SubscriptionRuleService::injectSingbox($injected, $legacy));
        config(['v2board.subscribe_ru_direct_enable' => 0]);
        $this->assertSame($config, SubscriptionRuleService::injectSingbox($config, $legacy));
    }

    public static function schemas(): array
    {
        return [[true], [false]];
    }
}
