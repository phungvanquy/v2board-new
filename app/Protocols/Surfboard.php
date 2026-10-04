<?php

namespace App\Protocols;

use App\Protocols\Contracts\ProtocolFormatter;
use App\Protocols\Support\NetworkSettings;
use App\Support\SubscriptionRuleService;
use App\Utils\Helper;

class Surfboard implements ProtocolFormatter
{
    public $flag = 'surfboard';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;

        $appName = config('v2board.app_name', 'V2Board');
        header("content-disposition:attachment;filename*=UTF-8''" . rawurlencode($appName) . '.conf');

        $proxies = '';
        $proxyGroup = '';

        foreach ($servers as $item) {
            if (($item['type'] ?? null) === 'v2node' && isset($item['protocol'])) {
                $item['type'] = $item['protocol'];
            }
            if ($item['type'] === 'shadowsocks'
                && in_array($item['cipher'], [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305',
                ])
            ) {
                // [Proxy]
                $proxies .= self::buildShadowsocks($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === 'vmess') {
                // [Proxy]
                $proxies .= self::buildVmess($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === 'trojan') {
                // [Proxy]
                $proxies .= self::buildTrojan($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === 'anytls') {
                // [Proxy]
                $proxies .= self::buildAnyTLS($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
        }

        $defaultConfig = base_path() . '/resources/rules/default.surfboard.conf';
        $customConfig = base_path() . '/resources/rules/custom.surfboard.conf';
        if (\File::exists($customConfig)) {
            $config = file_get_contents("$customConfig");
        } else {
            $config = file_get_contents("$defaultConfig");
        }

        $config = SubscriptionRuleService::injectText($config);

        // Subscription link
        $subsURL = Helper::getSubscribeUrl($user['token']);
        $subsDomain = $_SERVER['HTTP_HOST'];

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$subs_domain', $subsDomain, $config);
        $config = str_replace('$proxies', $proxies, $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);

        $upload = round($user['u'] / (1024 * 1024 * 1024), 2);
        $download = round($user['d'] / (1024 * 1024 * 1024), 2);
        $useTraffic = $upload + $download;
        $totalTraffic = round($user['transfer_enable'] / (1024 * 1024 * 1024), 2);
        $expireDate = $user['expired_at'] === null ? 'Never Expires' : date('Y-m-d H:i:s', $user['expired_at']);
        $subscribeInfo = "title={$appName} Subscription Info, content=Upload: {$upload}GB\\nDownload: {$download}GB\\nRemaining: {$useTraffic}GB\\nTotal: {$totalTraffic}GB\\nExpires: {$expireDate}";
        $config = str_replace('$subscribe_info', $subscribeInfo, $config);

        return $config;
    }

    public static function buildShadowsocks($password, $server)
    {
        $config = [
            "{$server['name']}=ss",
            "{$server['host']}",
            "{$server['port']}",
            "encrypt-method={$server['cipher']}",
            "password={$password}",
            'tfo=true',
            'udp-relay=true',
        ];
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";

        return $uri;
    }

    public static function buildVmess($uuid, $server)
    {
        $networkSettings = NetworkSettings::for($server);
        $tlsSettings = NetworkSettings::tls($server);
        $config = [
            "{$server['name']}=vmess",
            "{$server['host']}",
            "{$server['port']}",
            "username={$uuid}",
            'vmess-aead=true',
            'tfo=true',
            'udp-relay=true',
        ];

        if ($server['tls']) {
            array_push($config, 'tls=true');
            if ($tlsSettings) {
                if (isset($tlsSettings['allow_insecure']) && !empty($tlsSettings['allow_insecure'])) {
                    array_push($config, 'skip-cert-verify=' . ($tlsSettings['allow_insecure'] ? 'true' : 'false'));
                }
                if (isset($tlsSettings['server_name']) && !empty($tlsSettings['server_name'])) {
                    array_push($config, "sni={$tlsSettings['server_name']}");
                }
            }
        }
        if ($server['network'] === 'ws') {
            array_push($config, 'ws=true');
            if ($networkSettings) {
                $wsSettings = $networkSettings;
                if (isset($wsSettings['path']) && !empty($wsSettings['path'])) {
                    array_push($config, "ws-path={$wsSettings['path']}");
                }
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) {
                    array_push($config, "ws-headers=Host:{$wsSettings['headers']['Host']}");
                }
                if (isset($wsSettings['security'])) {
                    array_push($config, "encrypt-method={$wsSettings['security']}");
                }
            }
        }

        $uri = implode(',', $config);
        $uri .= "\r\n";

        return $uri;
    }

    public static function buildTrojan($password, $server)
    {
        $tlsSettings = NetworkSettings::tls($server);
        $sni = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        $insecure = $server['allow_insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0);
        $config = [
            "{$server['name']}=trojan",
            "{$server['host']}",
            "{$server['port']}",
            "password={$password}",
            $sni ? "sni={$sni}" : '',
            'tfo=true',
            'udp-relay=true',
        ];
        if ($insecure == 1) {
            array_push($config, 'skip-cert-verify=true');
        }
        if (isset($server['network']) && $server['network'] === 'ws') {
            array_push($config, 'ws=true');
            if (isset($server['network_settings']['path'])) {
                array_push($config, "ws-path={$server['network_settings']['path']}");
            }
            if (isset($server['network_settings']['headers']['Host'])) {
                array_push($config, "ws-headers=Host:{$server['network_settings']['headers']['Host']}");
            }
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";

        return $uri;
    }

    public static function buildAnyTLS($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $allowInsecure = ($server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1 ? 'true' : 'false';
        $sni = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');

        $config = [
            "{$server['name']}=anytls",
            "{$server['host']}",
            "{$server['port']}",
            "password={$password}",
            "skip-cert-verify={$allowInsecure}",
        ];

        if ($sni) {
            $config[] = "sni={$sni}";
        }
        $config[] = 'reuse=false';

        $uri = implode(', ', $config);
        $uri .= "\r\n";

        return $uri;
    }
}
