<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;

class GetLatestUrl extends Telegram
{
    public $command = '/getlatesturl';
    public $description = 'Get the latest site URL';

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;
        $text = sprintf(
            '%s\'s latest URL is: %s',
            config('v2board.app_name', 'V2Board'),
            config('v2board.app_url')
        );
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
