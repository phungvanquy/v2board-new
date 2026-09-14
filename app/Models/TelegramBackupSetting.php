<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $enabled
 * @property string $bot_token
 * @property string $chat_id
 * @property int $interval_hours
 * @property int|null $next_run_at
 * @property string $revision
 */
class TelegramBackupSetting extends Model
{
    protected $table = 'v2_telegram_backup_setting';

    public $timestamps = false;

    public $incrementing = false;

    protected $guarded = ['id'];

    protected $hidden = ['bot_token', 'revision'];

    protected $casts = [
        'enabled' => 'boolean',
        'interval_hours' => 'integer',
        'next_run_at' => 'integer',
    ];
}
