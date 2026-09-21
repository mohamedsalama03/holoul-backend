<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property string $id
 * @property string $recipient_id
 * @property string $type
 * @property string $title
 * @property string $message
 * @property string $resource_type
 * @property string $resource_id
 * @property string $input_hash
 * @property int $lock_version
 * @property ?Carbon $read_at
 */
final class Notification extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'read_at' => 'datetime'];
    }
}
