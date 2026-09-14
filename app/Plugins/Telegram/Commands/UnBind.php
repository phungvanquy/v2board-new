<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class UnBind extends Telegram
{
    public $command = '/unbind';
    public $description = 'Unbind your Telegram account from the website';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) {
            return;
        }
        $user = User::where('telegram_id', $message->chat_id)->first();
        $telegramService = $this->telegramService;
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, 'No user info found, please bind your account first', 'markdown');

            return;
        }
        $user->telegram_id = null;
        if (!$user->save()) {
            abort(500, 'Unbind failed');
        }
        $telegramService->sendMessage($message->chat_id, 'Unbound successfully', 'markdown');
    }
}
