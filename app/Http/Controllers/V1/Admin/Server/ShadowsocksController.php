<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerShadowsocksSave;
use App\Http\Requests\Admin\ServerShadowsocksUpdate;
use App\Models\ServerShadowsocks;
use App\Services\ServerIdService;
use Illuminate\Http\Request;

class ShadowsocksController extends Controller
{
    public function save(ServerShadowsocksSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, __('Server does not exist'));
            }
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, __('Save failed'));
            }

            return response([
                'data' => true,
            ]);
        }

        if (!ServerIdService::createWithGlobalId(ServerShadowsocks::class, $params)) {
            abort(500, __('Failed to create'));
        }

        return response([
            'data' => true,
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerShadowsocks::find($request->input('id'));
            if (!$server) {
                abort(500, __('Node ID does not exist'));
            }
        }

        return response([
            'data' => $server->delete(),
        ]);
    }

    public function update(ServerShadowsocksUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerShadowsocks::find($request->input('id'));

        if (!$server) {
            abort(500, __('This server does not exist'));
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true,
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerShadowsocks::find($request->input('id'));
        if (!$server) {
            abort(500, __('Server does not exist'));
        }
        $data = $server->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['show'] = 0;
        if (!ServerIdService::createWithGlobalId(ServerShadowsocks::class, $data)) {
            abort(500, __('Failed to copy'));
        }

        return response([
            'data' => true,
        ]);
    }
}
