<?php

namespace App\Services;

use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData,
        ]);

        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData,
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            $cached = Cache::get($jwt);
            if ($cached !== null) {
                if (!is_array($cached)) {
                    Cache::forget($jwt);
                    $cached = null;
                } else {
                    // The snapshot lives 3600s. A single-session revoke only
                    // removes the session entry (see removeSession(), which also
                    // forgets this snapshot) — but snapshots written before that
                    // fix, or raced with it, must still be rejected. The snapshot
                    // carries its session guid, so membership is verified first
                    // (no DB hit); role/ban drift is re-checked after.
                    if (isset($cached['_session'])
                        && !self::checkSession($cached['id'] ?? null, $cached['_session'])) {
                        Cache::forget($jwt);
                        $cached = null;
                    } else {
                        // Bans are revoked eagerly (admin/user controllers call
                        // removeAllSession(), which forgets these keys), but an
                        // is_admin/is_staff *demotion* does NOT wipe sessions — so
                        // without this re-check a freshly-demoted admin keeps panel
                        // access for the rest of the TTL. One indexed PK lookup
                        // detects the change; on drift we drop the snapshot and
                        // fall through to a full re-validate below.
                        $fresh = User::find($cached['id'], ['is_admin', 'is_staff', 'banned']);
                        if (!$fresh
                            || (int) $cached['is_admin'] !== (int) $fresh->is_admin
                            || (int) $cached['is_staff'] !== (int) $fresh->is_staff
                            || (int) $fresh->banned === 1) {
                            Cache::forget($jwt);
                            $cached = null;
                        } else {
                            return $cached;
                        }
                    }
                }
            }
            $data = (array) JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (!self::checkSession($data['id'], $data['session'])) {
                return false;
            }
            $user = User::select([
                'id',
                'email',
                'is_admin',
                'is_staff',
                'banned',
            ])
                ->find($data['id']);
            if (!$user || (int) $user->banned === 1) {
                return false;
            }
            $snapshot = $user->toArray();
            $snapshot['_session'] = $data['session'];
            Cache::put($jwt, $snapshot, 3600);

            return Cache::get($jwt);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array) Cache::get(CacheKey::get('USER_SESSIONS', $userId)) ?? [];
        if (!in_array($session, array_keys($sessions))) {
            return false;
        }

        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $userId);
        $sessions = (array) Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) {
            return false;
        }

        return true;
    }

    public function getSessions()
    {
        return (array) Cache::get(CacheKey::get('USER_SESSIONS', $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
        $sessions = (array) Cache::get($cacheKey, []);
        $meta = $sessions[$sessionId] ?? null;
        unset($sessions[$sessionId]);
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) {
            return false;
        }
        // The JWT snapshot cache (see decryptAuthData()) outlives the session
        // entry — forget it too, or the revoked token stays valid until TTL.
        if (is_array($meta) && isset($meta['auth_data'])) {
            Cache::forget($meta['auth_data']);
        }

        return true;
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
        $sessions = (array) Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }

        return Cache::forget($cacheKey);
    }
}
