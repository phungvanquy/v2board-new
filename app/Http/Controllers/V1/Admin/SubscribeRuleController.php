<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ConfigWriter;
use App\Support\SubscriptionRuleService;
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
        $domains = implode("\n", SubscriptionRuleService::normalizeDomainList((string) ($data['subscribe_ru_direct_domains'] ?? '')));

        ConfigWriter::save([
            'subscribe_ru_direct_enable' => $enable,
            'subscribe_ru_direct_domains' => $domains,
        ]);

        return response(['data' => true]);
    }
}
