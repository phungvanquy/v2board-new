<?php

declare(strict_types=1);

namespace App\Protocols\Support;

/**
 * Centralized network_settings/tls_settings handling shared by all
 * protocol URI builders. Replaces the per-protocol copies of
 * configureTcp/Ws/Grpc/Kcp/Httpupgrade/Xhttp.
 */
class NetworkSettings
{
    /**
     * Normalize the two historical key spellings used by node configs.
     *
     * @param  array  $server  node row as array
     * @return array
     */
    public static function for(array $server): array
    {
        return $server['network_settings'] ?? ($server['networkSettings'] ?? []);
    }

    public static function tls(array $server): array
    {
        return $server['tls_settings'] ?? ($server['tlsSettings'] ?? []);
    }

    /**
     * Apply network-specific settings onto $config by reference.
     */
    public static function apply(array $server, array &$config): void
    {
        $network = (string) ($server['network'] ?? '');
        $settings = self::for($server);

        match ($network) {
            'tcp' => self::configureTcpSettings($settings, $config),
            'ws' => self::configureWsSettings($settings, $config),
            'grpc' => self::configureGrpcSettings($settings, $config),
            'kcp' => self::configureKcpSettings($settings, $config),
            'httpupgrade' => self::configureHttpupgradeSettings($settings, $config),
            'xhttp' => self::configureXhttpSettings($settings, $config),
            default => null,
        };
    }

    public static function configureTcpSettings(array $settings, array &$config): void
    {
        $header = $settings['header'] ?? [];
        if (isset($header['type']) && $header['type'] === 'http') {
            $config['headerType'] = 'http';
            $config['host'] = $header['request']['headers']['Host'][0] ?? '';
            $config['path'] = $header['request']['path'][0] ?? '';
        }
    }

    public static function configureWsSettings(array $settings, array &$config): void
    {
        $config['path'] = $settings['path'] ?? '';
        $config['host'] = $settings['headers']['Host'] ?? '';
    }

    public static function configureGrpcSettings(array $settings, array &$config): void
    {
        $config['serviceName'] = $settings['serviceName'] ?? '';
    }

    public static function configureKcpSettings(array $settings, array &$config): void
    {
        $config['headerType'] = $settings['header']['type'] ?? 'none';
        if (isset($settings['seed'])) {
            $config['seed'] = $settings['seed'];
        }
    }

    public static function configureHttpupgradeSettings(array $settings, array &$config): void
    {
        $config['path'] = $settings['path'] ?? '';
        $config['host'] = $settings['host'] ?? '';
    }

    public static function configureXhttpSettings(array $settings, array &$config): void
    {
        $config['path'] = $settings['path'] ?? '';
        $config['host'] = $settings['host'] ?? '';
        $config['mode'] = $settings['mode'] ?? 'auto';
        $config['extra'] = isset($settings['extra']) ? json_encode($settings['extra'], JSON_UNESCAPED_SLASHES) : null;
    }
}
