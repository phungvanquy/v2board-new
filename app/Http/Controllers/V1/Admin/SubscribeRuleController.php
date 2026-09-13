<?php

namespace App\Http\Controllers\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SubscribeRuleController extends Controller
{
    public function fetch(Request $request)
    {
        return response([
            'data' => [
                'subscribe_ru_direct_enable' => (int) config('v2board.subscribe_ru_direct_enable', 1),
                'subscribe_ru_direct_domains' => (string) config('v2board.subscribe_ru_direct_domains', ''),
            ],
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'subscribe_ru_direct_enable' => 'required|in:0,1',
            'subscribe_ru_direct_domains' => 'nullable|string|max:65535',
        ]);

        $enable = (int) ($data['subscribe_ru_direct_enable'] ?? 1);
        $domains = (string) ($data['subscribe_ru_direct_domains'] ?? '');

        // Normalize domain list (strip empties, validate charset, dedup)
        $lines = preg_split('/[\r\n,;]+/', $domains) ?: [];
        $filtered = [];
        foreach ($lines as $line) {
            $d = strtolower(ltrim(trim($line), '.'));
            if ($d === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9.\-]+$/', $d) !== 1) {
                continue;
            }
            if (!in_array($d, $filtered, true)) {
                $filtered[] = $d;
            }
        }
        $domains = implode("\n", $filtered);

        $config = config('v2board');
        $config['subscribe_ru_direct_enable'] = $enable;
        $config['subscribe_ru_direct_domains'] = $domains;

        $exported = var_export($config, true);
        $path = base_path('config/v2board.php');
        if (\Illuminate\Support\Facades\File::put($path, "<?php\n return {$exported} ;") === false) {
            throw ApiException::fail(__('Update failed'));
        }
        if (function_exists('opcache_reset') && opcache_reset() === false) {
            throw ApiException::fail(__('Failed to clear the cache, please uninstall or check the opcache configuration'));
        }
        \Illuminate\Support\Facades\Artisan::call('config:cache');

        return response(['data' => true]);
    }
}
