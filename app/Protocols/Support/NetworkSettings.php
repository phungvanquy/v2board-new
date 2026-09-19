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
     * Normalize values consumed by strict Go JSON decoders in node backends.
     */
    public static function normalizeForNode(array $settings): array
    {
        $settings = self::normalizeStrictValues($settings);

        if (isset($settings['extra']) && is_array($settings['extra'])) {
            $settings['extra'] = self::normalizeStrictValues($settings['extra']);
        }

        if (isset($settings['xmux']) && is_array($settings['xmux'])) {
            self::normalizeInteger($settings['xmux'], 'hKeepAlivePeriod');
        }
        if (isset($settings['extra']['xmux']) && is_array($settings['extra']['xmux'])) {
            self::normalizeInteger($settings['extra']['xmux'], 'hKeepAlivePeriod');
        }

        foreach (['downloadSettings', 'extra'] as $container) {
            $downloadSettings = $container === 'extra'
                ? ($settings['extra']['downloadSettings'] ?? null)
                : ($settings['downloadSettings'] ?? null);

            if (!is_array($downloadSettings)) {
                continue;
            }

            self::normalizeInteger($downloadSettings, 'port');
            if ($container === 'extra') {
                $settings['extra']['downloadSettings'] = $downloadSettings;
            } else {
                $settings['downloadSettings'] = $downloadSettings;
            }
        }

        return $settings;
    }

    private static function normalizeStrictValues(array $settings): array
    {
        foreach ([
            'acceptProxyProtocol',
            'multiMode',
            'permit_without_stream',
            'xPaddingObfsMode',
            'noGRPCHeader',
            'noSSEHeader',
        ] as $key) {
            if (array_key_exists($key, $settings) && !is_bool($settings[$key])) {
                $settings[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach ([
            'heartbeatPeriod',
            'idle_timeout',
            'health_check_timeout',
            'initial_windows_size',
            'scMaxBufferedPosts',
            'serverMaxHeaderBytes',
        ] as $key) {
            self::normalizeInteger($settings, $key);
        }

        return $settings;
    }

    private static function normalizeInteger(array &$settings, string $key): void
    {
        if (array_key_exists($key, $settings) && !is_int($settings[$key])) {
            $settings[$key] = (int) $settings[$key];
        }
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
