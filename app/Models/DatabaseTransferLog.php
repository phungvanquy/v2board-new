<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $file_name
 * @property int|null $file_size
 * @property string|null $stored_path
 * @property string $status
 * @property string|null $message
 * @property int|null $created_at
 * @property int|null $updated_at
 */
class DatabaseTransferLog extends Model
{
    protected $table = 'v2_database_transfer_log';

    protected $dateFormat = 'U';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
