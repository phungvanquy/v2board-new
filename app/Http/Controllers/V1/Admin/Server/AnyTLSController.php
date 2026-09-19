<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerAnytls;
use App\Services\ServerIdService;
use App\Support\AnyTlsSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AnyTLSController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate([
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'server_name' => 'nullable',
            'insecure' => 'required|in:0,1',
            'padding_scheme' => 'nullable',
        ]);

        if (isset($params['padding_scheme'])) {
            try {
                $params['padding_scheme'] = AnyTlsSettings::fromAdmin($params['padding_scheme']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'padding_scheme' => $e->getMessage(),
                ]);
            }
        }

        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
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

        ServerIdService::createWithGlobalId(ServerAnytls::class, $params);

        return response([
            'data' => true,
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerAnytls::find($request->input('id'));
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

        $server = ServerAnytls::find($request->input('id'));

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
        $server = ServerAnytls::find($request->input('id'));
        if (!$server) {
            abort(500, __('Server does not exist'));
        }
        $data = $server->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['show'] = 0;
        ServerIdService::createWithGlobalId(ServerAnytls::class, $data);

        return response([
            'data' => true,
        ]);
    }
}
