<?php

declare(strict_types=1);

namespace Tests\Support;

class SubscriptionFixtures
{
    public static function server(string $protocol, bool $v2node = false): array
    {
        $server = [
            'id' => 1, 'name' => 'Compatibility node', 'host' => '192.0.2.1',
            'port' => 443, 'server_port' => 443, 'show' => 1, 'group_id' => [1],
            'parent_id' => null, 'sort' => 1, 'created_at' => 1700000000,
            'updated_at' => 1700000000, 'last_check_at' => 1700000000,
            'type' => $v2node ? 'v2node' : $protocol,
        ];
        $tls = ['server_name' => 'sni.example.com', 'allow_insecure' => 1];
        $network = ['path' => '/test-ws', 'headers' => ['Host' => 'ws.example.com']];
        if ($v2node) {
            $server += ['protocol' => $protocol, 'tls' => 1, 'tls_settings' => $tls,
                'network' => 'ws', 'network_settings' => $network];
        }
        if (in_array($protocol, ['vmess', 'vless', 'trojan'], true)) {
            $server += ['network' => 'ws'];
            if ($protocol === 'vmess' && !$v2node) {
                $server += ['tls' => 1, 'tlsSettings' => ['serverName' => 'sni.example.com', 'allowInsecure' => 1],
                    'networkSettings' => $network];
            } else {
                $server += ['network_settings' => $network];
            }
            if ($protocol === 'vless') {
                $server += ['tls' => 1, 'tls_settings' => $tls, 'flow' => null];
            }
            if ($protocol === 'trojan' && !$v2node) {
                $server += ['server_name' => 'sni.example.com', 'allow_insecure' => 1];
            }
        }
        if ($protocol === 'shadowsocks') {
            $server += ['cipher' => 'aes-128-gcm', 'obfs' => null, 'obfs_settings' => null];
        }
        if (in_array($protocol, ['anytls', 'tuic', 'hysteria', 'hysteria2'], true) && !$v2node) {
            $server += ['server_name' => 'sni.example.com', 'insecure' => 1];
        }
        if ($protocol === 'tuic') {
            $server += ['congestion_control' => 'bbr', 'udp_relay_mode' => 'native',
                'disable_sni' => 0, 'zero_rtt_handshake' => 0];
        }
        if (in_array($protocol, ['hysteria', 'hysteria2'], true)) {
            $server += ['up_mbps' => 100, 'down_mbps' => 100, 'obfs' => null, 'obfs_password' => null];
            if (!$v2node) {
                $server['version'] = 2;
            }
        }

        return $server;
    }
}
