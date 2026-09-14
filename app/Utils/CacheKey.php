<?php

namespace App\Utils;

class CacheKey
{
    public const KEYS = [
        'EMAIL_VERIFY_CODE' => 'Email verification code',
        'LAST_SEND_EMAIL_VERIFY_TIMESTAMP' => 'Last email verification code sent at',
        'SERVER_VMESS_ONLINE_USER' => 'Server online users',
        'SERVER_VMESS_LAST_CHECK_AT' => 'Server last checked at',
        'SERVER_VMESS_LAST_PUSH_AT' => 'Server last pushed at',
        'SERVER_TROJAN_ONLINE_USER' => 'Trojan server online users',
        'SERVER_TROJAN_LAST_CHECK_AT' => 'Trojan server last checked at',
        'SERVER_TROJAN_LAST_PUSH_AT' => 'Trojan server last pushed at',
        'SERVER_SHADOWSOCKS_ONLINE_USER' => 'Shadowsocks server online users',
        'SERVER_SHADOWSOCKS_LAST_CHECK_AT' => 'Shadowsocks server last checked at',
        'SERVER_SHADOWSOCKS_LAST_PUSH_AT' => 'Shadowsocks server last pushed at',
        'SERVER_HYSTERIA_ONLINE_USER' => 'Hysteria server online users',
        'SERVER_HYSTERIA_LAST_CHECK_AT' => 'Hysteria server last checked at',
        'SERVER_HYSTERIA_LAST_PUSH_AT' => 'Hysteria server last pushed at',
        'SERVER_TUIC_ONLINE_USER' => 'TUIC server online users',
        'SERVER_TUIC_LAST_CHECK_AT' => 'TUIC server last checked at',
        'SERVER_TUIC_LAST_PUSH_AT' => 'TUIC server last pushed at',
        'SERVER_VLESS_ONLINE_USER' => 'VLESS server online users',
        'SERVER_VLESS_LAST_CHECK_AT' => 'VLESS server last checked at',
        'SERVER_VLESS_LAST_PUSH_AT' => 'VLESS server last pushed at',
        'SERVER_ANYTLS_ONLINE_USER' => 'AnyTLS server online users',
        'SERVER_ANYTLS_LAST_CHECK_AT' => 'AnyTLS server last checked at',
        'SERVER_ANYTLS_LAST_PUSH_AT' => 'AnyTLS server last pushed at',
        'SERVER_V2NODE_ONLINE_USER' => 'V2Node server online users',
        'SERVER_V2NODE_LAST_CHECK_AT' => 'V2Node server last checked at',
        'SERVER_V2NODE_LAST_PUSH_AT' => 'V2Node server last pushed at',
        'TEMP_TOKEN' => 'Temporary token',
        'LAST_SEND_EMAIL_REMIND_TRAFFIC' => 'Last traffic reminder email sent',
        'SCHEDULE_LAST_CHECK_AT' => 'Scheduled task last checked at',
        'REGISTER_IP_RATE_LIMIT' => 'Registration rate limit',
        'LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP' => 'Last login link email sent at',
        'PASSWORD_ERROR_LIMIT' => 'Password error attempt limit',
        'USER_SESSIONS' => 'User sessions',
        'FORGET_REQUEST_LIMIT' => 'Password recovery request limit',
    ];

    public static function get(string $key, $uniqueValue)
    {
        if (!in_array($key, array_keys(self::KEYS))) {
            abort(500, 'key is not in cache key list');
        }

        return $key . '_' . $uniqueValue;
    }
}
