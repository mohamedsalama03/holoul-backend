<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $customer_kind
 * @property string $phone_e164
 * @property string $phone_display
 */
final class Customer extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];
}
