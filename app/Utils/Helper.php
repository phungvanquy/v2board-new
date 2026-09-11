<?php

declare(strict_types=1);

namespace App\Utils;

use App\Protocols\Support\NetworkSettings as ProtoNetworkSettings;
use App\Protocols\Support\UriString;
use App\Support\CryptoHelper;
use App\Support\SubscriptionHelper;
use App\Support\TrafficHelper;

/**
 * Backward-compatible facade for legacy callers.
 *
 * @deprecated Use App\Support\{CryptoHelper,TrafficHelper,SubscriptionHelper}
 *             and App\Protocols\Support\{UriString,NetworkSettings} directly.
 *             This facade will be removed in a future minor release.
 */
class Helper
{
    public static function uuidToBase64($uuid, $length)
    {
        return CryptoHelper::uuidToBase64((string) $uuid, (int) $length);
    }

    public static function getServerKey($timestamp, $length)
    {
        return CryptoHelper::getServerKey($timestamp, (int) $length);
    }

    public static function guid($format = false)
    {
        return CryptoHelper::guid((bool) $format);
    }

    public static function generateOrderNo(): string
    {
        $randomChar = mt_rand(10000, 99999);

        return date('YmdHms') . substr(microtime(), 2, 6) . $randomChar;
    }

    public static function exchange($from, $to)
    {
        $result = file_get_contents('https://api.exchangerate.host/latest?symbols=' . $to . '&base=' . $from);
        $result = json_decode($result, true);

        return $result['rates'][$to];
    }

    public static function randomChar($len, $special = false)
    {
        return CryptoHelper::randomChar((int) $len, (bool) $special);
    }

    public static function multiPasswordVerify($algo, $salt, $password, $hash)
    {
        switch ($algo) {
            case 'md5': return md5($password) === $hash;
            case 'sha256': return hash('sha256', $password) === $hash;
            case 'md5salt': return md5($password . $salt) === $hash;
            default: return password_verify($password, $hash);
        }
    }

    public static function emailSuffixVerify($email, $suffixs)
    {
        $suffix = preg_split('/@/', $email)[1];
        if (!$suffix) {
            return false;
        }
        if (!is_array($suffixs)) {
            $suffixs = preg_split('/,/', $suffixs);
        }
        if (!in_array($suffix, $suffixs)) {
            return false;
        }

        return true;
    }

    public static function trafficConvert(int $byte)
    {
        return TrafficHelper::trafficConvert($byte);
    }

    public static function getSubscribeUrl($token)
    {
        return SubscriptionHelper::getSubscribeUrl((string) $token);
    }

    public static function randomPort($range)
    {
        return CryptoHelper::randomPort((string) $range);
    }

    public static function base64EncodeUrlSafe($data)
    {
        return SubscriptionHelper::base64EncodeUrlSafe((string) $data);
    }

    public static function base64DecodeUrlSafe($data)
    {
        return SubscriptionHelper::base64DecodeUrlSafe((string) $data);
    }

    public static function encodeURIComponent($str)
    {
        return UriString::encodeURIComponent((string) $str);
    }

    public static function buildUri($uuid, $server)
    {
        if (($server['type'] ?? null) == 'v2node') {
            $server['type'] = $server['protocol'];
        }
        $method = 'build' . ucfirst((string) $server['type']) . 'Uri';
        if (method_exists(self::class, $method)) {
            return self::$method($uuid, $server);
        }

        return '';
    }

    public static function buildUriString($scheme, $auth, $server, $name, $params = [])
    {
        return UriString::buildUriString((string) $scheme, (string) $auth, (array) $server, (string) $name, (array) $params);
    }

    public static function formatHost($host)
    {
        return UriString::formatHost((string) $host);
    }

    public static function buildShadowsocksUri($uuid, $server)
    {
        $cipher = $server['cipher'];
        if (strpos($cipher, '2022-blake3') !== false) {
            $length = $cipher === '2022-blake3-aes-128-gcm' ? 16 : 32;
            $serverKey = self::getServerKey($server['created_at'], $length);
            $userKey = self::uuidToBase64($uuid, $length);
            $password = "{$serverKey}:{$userKey}";
        } else {
            $password = $uuid;
        }
        $name = rawurlencode($server['name']);
        $str = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode("{$cipher}:{$password}"));
        $add = self::formatHost($server['host']);
        $uri = "ss://{$str}@{$add}:{$server['port']}";
        if (($server['obfs'] ?? null) == 'http') {
            $uri .= "?plugin=obfs-local;obfs=http;obfs-host={$server['obfs-host']};path={$server['obfs-path']}";
        } elseif ((($server['network'] ?? null) == 'http') && isset($server['network_settings']['Host'])) {
            $path = $server['network_settings']['path'] ?? '/';
            $uri .= "?plugin=obfs-local;obfs=tls;obfs-host={$server['network_settings']['Host']};path={$path}";
        }

        return $uri . "#{$name}\r\n";
    }

    public static function buildVmessUri($uuid, $server)
    {
        $config = [
            'v' => '2',
            'ps' => $server['name'],
            'add' => self::formatHost($server['host']),
            'port' => (string) $server['port'],
            'id' => $uuid,
            'aid' => '0',
            'scy' => 'auto',
            'net' => $server['network'],
            'type' => 'none',
            'host' => '',
            'path' => '',
            'tls' => $server['tls'] ? 'tls' : '',
            'fp' => 'chrome',
        ];
        if ($server['tls']) {
            $tlsSettings = $server['tls_settings'] ?? $server['tlsSettings'] ?? [];
            $config['allowInsecure'] = (int) ($tlsSettings['allow_insecure'] ?? $tlsSettings['allowInsecure'] ?? 0);
            $config['sni'] = $tlsSettings['server_name'] ?? $tlsSettings['serverName'] ?? '';
            $config['pcs'] = $tlsSettings['pinned_peer_cert_sha256'] ?? '';
        }
        $network = (string) $server['network'];
        $networkSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
        switch ($network) {
            case 'tcp':
                if (!empty($networkSettings['header']['type']) && $networkSettings['header']['type'] === 'http') {
                    $config['type'] = $networkSettings['header']['type'];
                    $config['host'] = $networkSettings['header']['request']['headers']['Host'][0] ?? null;
                    $config['path'] = $networkSettings['header']['request']['path'][0] ?? null;
                }
                break;
            case 'ws':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['headers']['Host'] ?? null;
                isset($networkSettings['security']) && $config['scy'] = $networkSettings['security'];
                break;
            case 'grpc':
                $config['path'] = $networkSettings['serviceName'] ?? null;
                break;
            case 'kcp':
                if (isset($networkSettings['seed'])) {
                    $config['path'] = $networkSettings['seed'];
                }
                $config['type'] = $networkSettings['header']['type'] ?? 'none';
                break;
            case 'httpupgrade':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['host'] ?? null;
                break;
            case 'xhttp':
                $config['path'] = $networkSettings['path'] ?? null;
                $config['host'] = $networkSettings['host'] ?? null;
                $config['mode'] = $networkSettings['mode'] ?? 'auto';
                $config['extra'] = isset($networkSettings['extra']) ? json_encode($networkSettings['extra'], JSON_UNESCAPED_SLASHES) : null;
                break;
        }

        return 'vmess://' . base64_encode(json_encode($config)) . "\r\n";
    }

    public static function buildVlessUri($uuid, $server)
    {
        $name = self::encodeURIComponent($server['name']);
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            'type' => $server['network'],
            'encryption' => 'none',
            'host' => '',
            'path' => '',
            'headerType' => 'none',
            'quicSecurity' => 'none',
            'serviceName' => '',
            'security' => $server['tls'] != 0 ? ($server['tls'] == 2 ? 'reality' : 'tls') : '',
            'flow' => $server['flow'],
            'fp' => $tlsSettings['fingerprint'] ?? 'chrome',
            'insecure' => $tlsSettings['allow_insecure'] ?? 0,
            'pcs' => $tlsSettings['pinned_peer_cert_sha256'] ?? '',
        ];
        if ($server['tls']) {
            $tlsSettings = $server['tls_settings'] ?? [];
            $config['sni'] = $tlsSettings['server_name'] ?? '';
            if ($server['tls'] == 2) {
                $config['pbk'] = $tlsSettings['public_key'] ?? '';
                $config['sid'] = $tlsSettings['short_id'] ?? '';
            }
        }
        if (!empty($tlsSettings['ech'])) {
            if ($tlsSettings['ech'] === 'cloudflare') {
                $config['ech'] = 'cloudflare-ech.com+https://doh.pub/dns-query';
            } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                $config['ech'] = is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'][0] : $tlsSettings['ech_config'];
            }
        }
        if (isset($server['encryption']) && $server['encryption'] == 'mlkem768x25519plus') {
            $encSettings = $server['encryption_settings'];
            $enc = 'mlkem768x25519plus.' . ($encSettings['mode'] ?? 'native') . '.' . ($encSettings['rtt'] ?? '1rtt');
            if (isset($encSettings['client_padding']) && !empty($encSettings['client_padding'])) {
                $enc .= '.' . $encSettings['client_padding'];
            }
            $enc .= '.' . ($encSettings['password'] ?? '');
            $config['encryption'] = $enc;
        }
        self::configureNetworkSettings($server, $config);

        return self::buildUriString('vless', $uuid, $server, $name, $config);
    }

    public static function buildTrojanUri($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            'allowInsecure' => $server['allow_insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0),
            'peer' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? ''),
            'sni' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? ''),
            'pcs' => $tlsSettings['pinned_peer_cert_sha256'] ?? '',
            'type' => $server['network'],
        ];
        if (isset($server['network']) && in_array($server['network'], ['grpc', 'ws'])) {
            if ($server['network'] === 'grpc' && isset($server['network_settings']['serviceName'])) {
                $config['serviceName'] = $server['network_settings']['serviceName'];
            }
            if ($server['network'] === 'ws') {
                if (isset($server['network_settings']['path'])) {
                    $config['path'] = $server['network_settings']['path'];
                }
                if (isset($server['network_settings']['headers']['Host'])) {
                    $config['host'] = $server['network_settings']['headers']['Host'];
                }
            }
        }
        if (!empty($tlsSettings['ech'])) {
            if ($tlsSettings['ech'] === 'cloudflare') {
                $config['ech'] = 'cloudflare-ech.com+https://doh.pub/dns-query';
            } elseif ($tlsSettings['ech'] === 'custom' && !empty($tlsSettings['ech_config'])) {
                $config['ech'] = is_array($tlsSettings['ech_config']) ? $tlsSettings['ech_config'][0] : $tlsSettings['ech_config'];
            }
        }
        $query = http_build_query($config);

        return "trojan://{$password}@" . self::formatHost($server['host']) . ":{$server['port']}?{$query}#" . rawurlencode($server['name']) . "\r\n";
    }

    public static function buildHysteriaUri($password, $server)
    {
        $remote = self::formatHost($server['host']);
        $name = self::encodeURIComponent($server['name']);
        $parts = explode(',', $server['port']);
        $firstPort = strpos($parts[0], '-') !== false ? explode('-', $parts[0])[0] : $parts[0];
        $uri = $server['version'] == 2 ?
            "hysteria2://{$password}@{$remote}:{$firstPort}/?insecure={$server['insecure']}&sni={$server['server_name']}" :
            "hysteria://{$remote}:{$firstPort}/?protocol=udp&auth={$password}&insecure={$server['insecure']}&peer={$server['server_name']}&upmbps={$server['down_mbps']}&downmbps={$server['up_mbps']}";
        if (isset($server['obfs']) && isset($server['obfs_password'])) {
            $obfs_password = rawurlencode($server['obfs_password']);
            $uri .= $server['version'] == 2 ?
                "&obfs={$server['obfs']}&obfs-password={$obfs_password}" :
                "&obfs={$server['obfs']}&obfsParam{$obfs_password}";
        }
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $uri .= "&mport={$server['mport']}";
        }

        return "{$uri}#{$name}\r\n";
    }

    public static function buildHysteria2Uri($password, $server)
    {
        $remote = self::formatHost($server['host']);
        $name = self::encodeURIComponent($server['name']);
        $parts = explode(',', $server['port']);
        $firstPort = strpos($parts[0], '-') !== false ? explode('-', $parts[0])[0] : $parts[0];
        $tlsSettings = $server['tls_settings'] ?? [];
        $insecure = $tlsSettings['allow_insecure'] ?? 0;
        $sni = $tlsSettings['server_name'] ?? '';
        $pcs = $tlsSettings['pinned_peer_cert_sha256'] ?? '';
        $uri = "hysteria2://{$password}@{$remote}:{$firstPort}/?insecure={$insecure}&sni={$sni}&pcs={$pcs}";
        if (isset($server['obfs']) && isset($server['obfs_password'])) {
            $obfs_password = rawurlencode($server['obfs_password']);
            $uri .= "&obfs={$server['obfs']}&obfs-password={$obfs_password}";
        }
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $uri .= "&mport={$server['mport']}";
        }

        return "{$uri}#{$name}\r\n";
    }

    public static function buildTuicUri($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            'sni' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? ''),
            'alpn' => 'h3',
            'congestion_control' => $server['congestion_control'],
            'allow_insecure' => $server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0),
            'disable_sni' => $server['disable_sni'],
            'udp_relay_mode' => $server['udp_relay_mode'],
            'pcs' => $tlsSettings['pinned_peer_cert_sha256'] ?? '',
        ];
        $remote = self::formatHost($server['host']);
        $port = $server['port'];
        $name = self::encodeURIComponent($server['name']);
        $query = http_build_query($config);

        return "tuic://{$password}:{$password}@{$remote}:{$port}?{$query}#{$name}\r\n";
    }

    public static function buildAnytlsUri($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $config = [
            'type' => $server['network'] ?? 'tcp',
            'insecure' => $server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0),
            'fp' => $tlsSettings['fingerprint'] ?? 'chrome',
            'pcs' => $tlsSettings['pinned_peer_cert_sha256'] ?? '',
        ];
        if (isset($server['server_name']) || isset($tlsSettings['server_name'])) {
            $config['sni'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        }
        if (isset($server['tls']) && $server['tls'] == 2) {
            $config['security'] = 'reality';
            $config['pbk'] = $tlsSettings['public_key'] ?? '';
            $config['sid'] = $tlsSettings['short_id'] ?? '';
        }
        $remote = self::formatHost($server['host']);
        $port = $server['port'];
        $name = self::encodeURIComponent($server['name']);
        if (isset($server['network']) && isset($server['network_settings'])) {
            self::configureNetworkSettings($server, $config);
        }
        $query = http_build_query($config);

        return "anytls://{$password}@{$remote}:{$port}/?{$query}#{$name}\r\n";
    }

    public static function generateEchKeyPair($outerSni)
    {
        return CryptoHelper::generateEchKeyPair((string) $outerSni);
    }

    public static function configureNetworkSettings($server, &$config)
    {
        ProtoNetworkSettings::apply((array) $server, $config);
    }

    public static function configureTcpSettings($settings, &$config)
    {
        ProtoNetworkSettings::configureTcpSettings((array) $settings, $config);
    }

    public static function configureWsSettings($settings, &$config)
    {
        ProtoNetworkSettings::configureWsSettings((array) $settings, $config);
    }

    public static function configureGrpcSettings($settings, &$config)
    {
        ProtoNetworkSettings::configureGrpcSettings((array) $settings, $config);
    }

    public static function configureKcpSettings($settings, &$config)
    {
        ProtoNetworkSettings::configureKcpSettings((array) $settings, $config);
    }

    public static function configureHttpupgradeSettings($settings, &$config)
    {
        ProtoNetworkSettings::configureHttpupgradeSettings((array) $settings, $config);
    }

    public static function configureXhttpSettings($settings, &$config)
    {
        ProtoNetworkSettings::configureXhttpSettings((array) $settings, $config);
    }
}
