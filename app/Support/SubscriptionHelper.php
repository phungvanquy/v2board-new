<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SubscriptionHelper
{
    /**
     * Build the plain subscription URL for a token.
     *
     * In method 2 (time-based HMAC) the user id is embedded in the token, so
     * pass it when known (e.g. in a list loop) to avoid one User query per row.
     * When omitted, it is resolved from the token; unknown tokens return null.
     */
    public static function getSubscribeUrl(string $token, ?int $userId = null): ?string
    {
        $submethod = (int) config('v2board.show_subscribe_method', 0);
        $path = config('v2board.subscribe_path', '/api/v1/client/subscribe');
        if (empty($path)) {
            $path = '/api/v1/client/subscribe';
        }
        $subscribeUrls = explode(',', (string) config('v2board.subscribe_url'));
        $subscribeUrl = $subscribeUrls[rand(0, count($subscribeUrls) - 1)];
        switch ($submethod) {
            case 0:
                $path = "{$path}?token={$token}";
                if ($subscribeUrl) {
                    return $subscribeUrl . $path;
                }

                return url($path);
            case 1:
                $newtoken = Cache::get("otp_{$token}");
                if (!$newtoken) {
                    $newtoken = self::base64EncodeUrlSafe(random_bytes(24));
                    $added = Cache::add("otp_{$token}", $newtoken, 86400);
                    if ($added) {
                        Cache::put("otpn_{$newtoken}", $token, 86400);
                    } else {
                        $newtoken = Cache::get("otp_{$token}");
                    }
                }
                $path = "{$path}?token={$newtoken}";
                if ($subscribeUrl) {
                    return $subscribeUrl . $path;
                }

                return url($path);
            case 2:
                $timestep = (int) config('v2board.show_subscribe_expire', 5) * 60;
                $counter = (int) floor(time() / $timestep);
                $counterBytes = pack('N*', 0) . pack('N*', $counter);
                $hash = hash_hmac('sha1', $counterBytes, $token, false);
                if ($userId === null) {
                    $user = User::where('token', $token)->select('id')->first();
                    if ($user === null) {
                        return null;
                    }
                    $userId = (int) $user->id;
                }
                $newtoken = self::base64EncodeUrlSafe("{$userId}:{$hash}");
                $path = "{$path}?token={$newtoken}";
                if ($subscribeUrl) {
                    return $subscribeUrl . $path;
                }

                return url($path);
            default:
                return null;
        }
    }

    public static function base64EncodeUrlSafe(string $data): string
    {
        $encoded = base64_encode($data);

        return str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
    }

    public static function base64DecodeUrlSafe(string $data): string
    {
        $b64 = str_replace(['-', '_'], ['+', '/'], $data);
        $pad = 4 - (strlen($b64) % 4);
        if ($pad < 4) {
            $b64 .= str_repeat('=', $pad);
        }

        return (string) base64_decode($b64);
    }

    public static function encodeURIComponent(string $str): string
    {
        $revert = ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')'];

        return strtr(rawurlencode($str), $revert);
    }
}
