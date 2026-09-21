<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $notification_id
 * @property string $state
 * @property ?string $operation_id
 * @property int $generation
 * @property int $lock_version
 * @property ?int $send_fence
 * @property ?string $failure_code
 */
final class NotificationDelivery extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'lock_version' => 'integer', 'send_fence' => 'integer'];
    }
}
