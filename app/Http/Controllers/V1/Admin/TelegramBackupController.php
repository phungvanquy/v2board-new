<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\TelegramBackupService;
use Illuminate\Http\Request;

class TelegramBackupController extends Controller
{
    public function __construct(private TelegramBackupService $service)
    {
    }

    public function fetch()
    {
        return response(['data' => $this->service->summary()]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|required|boolean',
            'bot_token' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/^[0-9]+:[A-Za-z0-9_-]+$/'],
            'chat_id' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^-?[1-9][0-9]{0,19}$/'],
            'interval_hours' => 'sometimes|required|integer|min:1|max:168',
        ]);
        $this->service->save($data);

        return response(['data' => $this->service->summary()]);
    }

    public function backup()
    {
        $id = $this->service->enqueue((int) request()->input('user.id', 0));

        return response(['data' => ['id' => $id, 'status' => 'pending']], 202);
    }
}
