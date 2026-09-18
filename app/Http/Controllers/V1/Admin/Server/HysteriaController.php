<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerHysteria;
use App\Services\ServerIdService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class HysteriaController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'version' => 'required|in:1,2',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'up_mbps' => 'nullable|numeric',
            'down_mbps' => 'nullable|numeric',
            'obfs' => 'nullable',
            'obfs_password' => 'nullable',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1',
        ]);

        if (!isset($params['up_mbps'])) {
            $params['up_mbps'] = 0;
        }
        if (!isset($params['down_mbps'])) {
            $params['down_mbps'] = 0;
        }

        if (isset($params['obfs'])) {
            if (!isset($params['obfs_password'])) {
                $params['obfs_password'] = Helper::getServerKey($request->input('created_at'), 16);
            }
        } else {
            $params['obfs_password'] = null;
        }

        if ($request->input('id')) {
            $server = ServerHysteria::find($request->input('id'));
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

        if (!ServerIdService::createWithGlobalId(ServerHysteria::class, $params)) {
            abort(500, __('Failed to create'));
        }

        return response([
            'data' => true,
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerHysteria::find($request->input('id'));
            if (!$server) {
                abort(500, __('Node ID does not exist'));
            }
        }

        return response([
            'data' => $server->delete(),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1',
        ], [
            'show.in' => __('The display status is invalid'),
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerHysteria::find($request->input('id'));

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
        $server = ServerHysteria::find($request->input('id'));
        if (!$server) {
            abort(500, __('Server does not exist'));
        }
        $data = $server->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['show'] = 0;
        if (!ServerIdService::createWithGlobalId(ServerHysteria::class, $data)) {
            abort(500, __('Failed to copy'));
        }

        return response([
            'data' => true,
        ]);
    }
}
