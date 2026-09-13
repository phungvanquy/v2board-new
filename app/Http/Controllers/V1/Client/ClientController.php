<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $result = null;
            if ($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            $result = $class->handle();
                            break;
                        }
                    }
                }
                if (is_null($result) && strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && version_compare($version, '1.12.0', '>=')) {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    $result = $class->handle();
                }
            }
            if (is_null($result)) {
                $class = new General($user, $servers);
                $result = $class->handle();
            }

            // Every client (Happ, V2rayNG, Loon, …) that falls through to
            // General, or returns a bare body, would otherwise get no
            // subscription metadata at all: no expire time, no traffic, no
            // profile title. Attach the standard headers to any response that
            // does not already carry them (protocol classes that set their
            // own — Clash*, Singbox, v2RayTun — win).
            return $this->attachSubscriptionHeaders($result, $user);
        }
    }

    /**
     * Ensure the subscription response advertises user metadata via the
     * de-facto standard subscription headers. Existing headers are kept,
     * so per-protocol formatting is never overwritten.
     *
     * @param string|\Symfony\Component\HttpFoundation\Response $result
     * @param \App\Models\User $user
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    private function attachSubscriptionHeaders($result, $user)
    {
        // Protocols like Clash/Stash/Surge use raw header() instead of a
        // Response object. Those headers are queued globally and would be
        // sent alongside the Response's headers (duplicate subscription-
        // userinfo etc., and Clash's `expire=` is empty for never-expire
        // instead of 0). Remove the raw ones so the Response carries the
        // single, correct set. Safe under webman (isWEBMAN) too — it's a
        // no-op if nothing was queued or headers were already sent.
        if (function_exists('header_remove') && !headers_sent()) {
            // Surge/Surfboard emit "<appName>.conf" as the download filename.
            // Preserve a protocol-set content-disposition (incl. its .conf
            // extension); only the other metadata headers are normalized below.
            foreach (['subscription-userinfo', 'profile-update-interval', 'profile-title', 'profile-web-page-url', 'support-url'] as $h) {
                @header_remove($h);
            }
        }
        if (!($result instanceof \Symfony\Component\HttpFoundation\Response)) {
            $result = response($result);
        }
        $headers = $result->headers;
        if (!$headers->has('subscription-userinfo')) {
            // upload/download/total are bytes, expire is a unix timestamp
            // (0 = never expires, which some clients fail to parse as empty).
            $headers->set('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire=" . ((int) $user['expired_at'] ?: 0));
        }
        if (!$headers->has('profile-update-interval')) {
            $headers->set('profile-update-interval', '24');
        }
        $appName = config('v2board.app_name', 'V2Board');
        if (!$headers->has('profile-title')) {
            $headers->set('profile-title', 'base64:' . base64_encode($appName));
        }
        if (!$headers->has('content-disposition')) {
            // Kept in sync with the raw-header cleanup above: protocols that
            // set their own filename (e.g. Surge/Surfboard "<appName>.conf")
            // are preserved via headers_sent-safe raw headers; only set the
            // default when nothing else did.
            $headers->set('content-disposition', 'attachment;filename*=UTF-8\'\'' . rawurlencode($appName));
        }
        $appUrl = (string) config('v2board.app_url');
        if ($appUrl !== '' && !$headers->has('profile-web-page-url')) {
            $headers->set('profile-web-page-url', $appUrl);
        }

        return $result;
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) {
            return;
        }
        if (!(int) config('v2board.show_info_to_server_enable', 0)) {
            return;
        }
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
