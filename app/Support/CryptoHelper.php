<?php

declare(strict_types=1);

namespace App\Support;

class CryptoHelper
{
    public static function uuidToBase64(string $uuid, int $length): string
    {
        return base64_encode(substr($uuid, 0, $length));
    }

    public static function getServerKey(mixed $timestamp, int $length): string
    {
        return base64_encode(substr(md5((string) $timestamp), 0, $length));
    }

    public static function guid(bool $format = false): string
    {
        if (function_exists('com_create_guid') === true) {
            return md5(trim(com_create_guid(), '{}'));
        }
        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        if ($format) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        return md5(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)) . '-' . time());
    }

    public static function randomChar(int $len, bool $special = false): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ($special) {
            $chars .= '!@#$?|{/:%^&*()-_[]}<>=+,.';
        }
        $str = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, $max)];
        }

        return $str;
    }

    public static function randomPort(string $range): int
    {
        $portRange = explode('-', $range);

        return (int) rand((int) $portRange[0], (int) $portRange[1]);
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair for sing-box.
     *
     * @param string $outerSni cover/front domain for the outer ClientHello SNI
     * @return array{ech_key: string, ech_config: string}
     */
    public static function generateEchKeyPair(string $outerSni): array
    {
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);
        $configId = random_int(0, 255);
        $configData = pack('C', $configId);
        $configData .= pack('n', 0x0020);
        $configData .= pack('n', 32) . $publicKey;
        $suites = pack('nnnnnn', 0x0001, 0x0001, 0x0001, 0x0002, 0x0001, 0x0003);
        $configData .= pack('n', strlen($suites)) . $suites;
        $configData .= pack('C', 0);
        $configData .= pack('C', strlen($outerSni)) . $outerSni;
        $configData .= pack('n', 0);
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($configData)) . $configData;
        $echConfigList = $echConfig;
        $echKeys = pack('n', strlen($echConfig)) . $echConfig;
        $echKeys .= pack('n', 1);
        $echKeys .= pack('C', $configId);
        $echKeys .= pack('n', 32) . $privateKey;

        return [
            'ech_key' => base64_encode($echKeys),
            'ech_config' => base64_encode($echConfigList),
        ];
    }
}
