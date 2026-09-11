<?php

declare(strict_types=1);

namespace App\Protocols\Support;

class UriString
{
    public static function buildUriString(string $scheme, string $auth, array $server, string $name, array $params = []): string
    {
        $host = self::formatHost($server['host']);
        $port = $server['port'];
        $query = http_build_query($params);

        return "{$scheme}://{$auth}@{$host}:{$port}?{$query}#{$name}\r\n";
    }

    public static function formatHost(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$host]" : $host;
    }

    public static function encodeURIComponent(string $str): string
    {
        $revert = ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')'];

        return strtr(rawurlencode($str), $revert);
    }

    public static function base64UrlSafeEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
