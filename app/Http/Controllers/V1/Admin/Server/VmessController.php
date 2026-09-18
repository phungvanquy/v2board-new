<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerVmessSave;
use App\Http\Requests\Admin\ServerVmessUpdate;
use App\Models\ServerVmess;
use App\Services\ServerIdService;
use Illuminate\Http\Request;

class VmessController extends Controller
{
    public function save(ServerVmessSave $request)
    {
        $params = $request->validated();

        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
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

        ServerIdService::createWithGlobalId(ServerVmess::class, $params);

        return response([
            'data' => true,
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerVmess::find($request->input('id'));
            if (!$server) {
                abort(500, __('Node ID does not exist'));
            }
        }

        return response([
            'data' => $server->delete(),
        ]);
    }

    public function update(ServerVmessUpdate $request)
    {
        $params = $request->only([
            'show',
        ]);

        $server = ServerVmess::find($request->input('id'));

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
        $server = ServerVmess::find($request->input('id'));
        if (!$server) {
            abort(500, __('Server does not exist'));
        }
        $data = $server->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['show'] = 0;
        ServerIdService::createWithGlobalId(ServerVmess::class, $data);

        return response([
            'data' => true,
        ]);
    }
}
