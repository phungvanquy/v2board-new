<?php

namespace App\Http\Controllers\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\HappCryptoService;
use App\Support\SubscriptionHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class HappCryptoController extends Controller
{
    public function fetch(Request $request)
    {
        return response([
            'data' => [
                'happ_crypto_public_key' => (string) config('v2board.happ_crypto_public_key', ''),
                'happ_crypto_use_remote' => (int) config('v2board.happ_crypto_use_remote', 0),
                'happ_crypto_cache_ttl' => (int) config('v2board.happ_crypto_cache_ttl', 3600),
                'default_mode' => HappCryptoService::configuredMode(),
                'local_openssl_available' => function_exists('openssl_public_encrypt'),
            ],
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'happ_crypto_public_key' => 'nullable|string|max:8192',
            'happ_crypto_use_remote' => 'required|in:0,1',
            'happ_crypto_cache_ttl' => 'required|integer|min:60|max:86400',
        ]);

        $pem = trim((string) ($data['happ_crypto_public_key'] ?? ''));
        if ($pem !== '' && strpos($pem, 'BEGIN PUBLIC KEY') === false) {
            throw ApiException::fail(__('The public key must be a PEM block containing "BEGIN PUBLIC KEY".'));
        }
        if ($pem !== '') {
            if (!function_exists('openssl_pkey_get_public') || openssl_pkey_get_public($pem) === false) {
                throw ApiException::fail(__('The public key is not a valid RSA PEM.'));
            }
            // crypt4 is RSA-4096/PKCS#1 v1.5 specifically. openssl_pkey_get_public()
            // accepts EC and smaller RSA keys that cannot produce a valid crypt4
            // link (or silently produce a wrong-size ciphertext) — reject them.
            $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
            if (!$details
                || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA
                || (int) ($details['bits'] ?? 0) !== 4096) {
                throw ApiException::fail(__('The public key must be a 4096-bit RSA key.'));
            }
        }

        $config = config('v2board');
        $config['happ_crypto_public_key'] = $pem;
        $config['happ_crypto_use_remote'] = (int) $data['happ_crypto_use_remote'];
        $config['happ_crypto_cache_ttl'] = (int) $data['happ_crypto_cache_ttl'];
        // Changing mode/key must not serve previously cached links. Random
        // version (not time()): two saves within the same second must not
        // share a version or old ciphertext keeps being served.
        $config['happ_crypto_cache_version'] = bin2hex(random_bytes(8));

        $exported = var_export($config, true);
        $path = base_path('config/v2board.php');
        if (File::put($path, "<?php\n return {$exported} ;") === false) {
            throw ApiException::fail(__('Update failed'));
        }
        // Order matters: the rebuildable artifact is bootstrap/cache/config.php,
        // so config:cache must run even if opcache_reset() reports failure
        // (it returns false when opcache is installed but disabled). A failed
        // opcache flush is cosmetic — surface it, don't abort half-saved.
        $opcacheWarn = false;
        if (function_exists('opcache_reset') && opcache_reset() === false) {
            $opcacheWarn = true;
        }
        Artisan::call('config:cache');

        // Webman boots the Laravel kernel once per worker and keeps config in
        // memory; config:cache only updates what fresh processes read. Signal a
        // reload like ConfigController@save does, or other workers keep serving
        // the previous key/mode (and the remote/local default) until restart.
        $reloaded = true;
        if (Cache::has('WEBMANPID')) {
            $pid = Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            $reloaded = (bool) posix_kill((int) $pid, 15);
        }

        return response([
            'data' => [
                'ok' => true,
                'default_mode' => HappCryptoService::configuredMode(),
                'opcache_warning' => $opcacheWarn,
                'worker_reload' => $reloaded,
            ],
        ]);
    }

    /**
     * Convert an arbitrary subscription URL (e.g. one copied from the user list)
     * into its happ:// deep link. Works independently of the enable toggle.
     * Optional 'mode' ('local' | 'remote') overrides the configured default for
     * this single call so the converter can try both without saving settings.
     */
    public function encrypt(Request $request)
    {
        $data = $request->validate([
            'url' => 'required|url|max:2048',
            'mode' => 'nullable|in:local,remote',
        ]);
        $plain = trim($data['url']);
        $mode = $data['mode'] ?? null;

        if ($mode === null) {
            $mode = HappCryptoService::configuredMode();
        }

        if ($mode === HappCryptoService::MODE_LOCAL && strlen($plain) > HappCryptoService::MAX_LOCAL_PLAIN) {
            throw ApiException::fail(__('The URL is too long for local RSA (max 501 characters). Use the remote option instead.'));
        }

        $result = HappCryptoService::encrypt($plain, $mode);
        if ($result === null) {
            throw ApiException::fail($mode === HappCryptoService::MODE_REMOTE
                ? __('Remote encryption failed — crypto.happ.su is unreachable or refused the request.')
                : __('Local encryption failed — check the RSA public key.'));
        }

        return response([
            'data' => [
                'plain' => $plain,
                'happ' => $result['link'],
                'mode' => $result['mode'],
            ],
        ]);
    }

    /**
     * Look up a user's plain subscription URL by email, so the admin does not
     * have to copy it out of the user list first.
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);
        $user = User::where('email', $data['email'])->select('token')->first();
        if (!$user) {
            throw ApiException::fail(__('The user does not exist'));
        }
        $plain = SubscriptionHelper::getSubscribeUrl((string) $user->token);
        if ($plain === null || $plain === '') {
            throw ApiException::fail(__('Could not build a subscription URL for this user'));
        }

        return response([
            'data' => [
                'plain' => $plain,
            ],
        ]);
    }
}
