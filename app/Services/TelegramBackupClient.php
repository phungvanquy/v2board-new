<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

class TelegramBackupClient
{
    // Telegram's hosted Bot API sendDocument limit.
    public const MAX_FILE_BYTES = 50 * 1024 * 1024;

    public function sendDocument(string $token, string $chatId, string $path, string $filename): void
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw ApiException::fail(__('The full backup file is empty or missing.'));
        }
        if (filesize($path) > self::MAX_FILE_BYTES) {
            throw ApiException::badRequest(__('The full backup exceeds Telegram’s 50 MB upload limit. Use Download export to save it.'));
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw ApiException::fail(__('Could not read the full backup file.'));
        }

        try {
            // Never follow redirects or expose HTTP exceptions: the request URL
            // contains the bot token, and its body contains the full backup.
            try {
                $response = Http::timeout(300)
                    ->withOptions(['allow_redirects' => false, 'connect_timeout' => 10])
                    ->attach('document', $stream, $filename)
                    ->post('https://api.telegram.org/bot' . $token . '/sendDocument', [
                        'chat_id' => $chatId,
                        'caption' => 'V2Board full backup — ' . $filename,
                    ]);
            } catch (\Throwable $e) {
                throw ApiException::fail(__('Could not upload the backup to Telegram. Check connectivity and try again.'));
            }

            if (!$response->successful() || $response->json('ok') !== true || !$response->json('result.message_id')) {
                $code = (int) ($response->json('error_code') ?? $response->status());
                $message = match ($code) {
                    401 => __('Telegram rejected the backup bot token.'),
                    400, 403 => __('Telegram could not deliver the backup. Check the chat ID, start the bot in a private chat, or allow it to send files in the group.'),
                    413 => __('Telegram rejected the backup because the file is too large.'),
                    429 => __('Telegram’s rate limit was reached. Try the backup again later.'),
                    default => __('Telegram did not confirm delivery of the backup. Check the destination before trying again.'),
                };
                throw ApiException::fail($message);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
