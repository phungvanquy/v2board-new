<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Single home for persisting v2board settings to config/v2board.php.
 *
 * Replaces the copy-pasted var_export + File::put + opcache_reset +
 * config:cache + WEBMANPID ritual that lived in ConfigController,
 * SubscribeRuleController and HappCryptoController with divergent behavior.
 */
class ConfigWriter
{
    /**
     * Merge $updates into the v2board config and persist them.
     *
     * @param array<string, mixed> $updates  keys to overwrite in config('v2board')
     * @param bool                 $strictOpcache  throw on opcache_reset() failure
     *                                             (true) or report it as a warning (false)
     *
     * @return array{opcache_warning: bool, worker_reload: bool|null}
     *         worker_reload is null when not running under Webman (no WEBMANPID),
     *         otherwise whether the reload signal was delivered.
     *
     * @throws ApiException on write failure (and on opcache failure when strict)
     */
    public static function save(array $updates, bool $strictOpcache = true): array
    {
        $config = config('v2board');
        foreach ($updates as $key => $value) {
            $config[$key] = $value;
        }

        $exported = var_export($config, true);
        if (File::put(base_path('config/v2board.php'), "<?php\n return {$exported} ;") === false) {
            throw ApiException::fail(__('Update failed'));
        }

        $opcacheWarning = false;
        if (function_exists('opcache_reset') && opcache_reset() === false) {
            if ($strictOpcache) {
                throw ApiException::fail(__('Failed to clear the cache, please uninstall or check the opcache configuration'));
            }
            $opcacheWarning = true;
        }
        Artisan::call('config:cache');

        // Webman boots the Laravel kernel once per worker and keeps config in
        // memory; config:cache only updates what fresh processes read, so
        // signal a reload or workers keep serving the previous settings.
        $workerReload = null;
        if (Cache::has('WEBMANPID')) {
            $pid = (int) Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            $workerReload = function_exists('posix_kill') ? (bool) posix_kill($pid, 15) : false;
        }

        return [
            'opcache_warning' => $opcacheWarning,
            'worker_reload' => $workerReload,
        ];
    }
}
