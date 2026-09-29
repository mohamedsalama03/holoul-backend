<?php

declare(strict_types=1);

namespace App\Modules\Contact\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $reference
 * @property ?string $full_name
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $company
 * @property ?string $message
 * @property string $status
 * @property int $lock_version
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $updated_at
 * @property ?CarbonImmutable $redacted_at
 */
final class ContactMessage extends Model
{
    protected $table = 'contact_messages';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['full_name', 'email', 'phone', 'company', 'message'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['full_name' => 'encrypted', 'email' => 'encrypted', 'phone' => 'encrypted', 'company' => 'encrypted', 'message' => 'encrypted',
            'lock_version' => 'integer', 'received_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'redacted_at' => 'immutable_datetime'];
    }
}
