<?php

namespace App\Support;

use App\Utils\Helper;
use ParagonIE_Sodium_Compat as SodiumCompat;

class RealitySettings
{
    public static function withDefaults(array $settings): array
    {
        if (empty($settings['public_key']) || empty($settings['private_key'])) {
            $keyPair = SodiumCompat::crypto_box_keypair();
            if (empty($settings['public_key'])) {
                $settings['public_key'] = Helper::base64EncodeUrlSafe(
                    SodiumCompat::crypto_box_publickey($keyPair)
                );
            }
            if (empty($settings['private_key'])) {
                $settings['private_key'] = Helper::base64EncodeUrlSafe(
                    SodiumCompat::crypto_box_secretkey($keyPair)
                );
            }
        }

        if (empty($settings['short_id'])) {
            $settings['short_id'] = substr(sha1($settings['private_key']), 0, 8);
        }
        if (empty($settings['server_port'])) {
            $settings['server_port'] = '443';
        }

        return $settings;
    }
}
