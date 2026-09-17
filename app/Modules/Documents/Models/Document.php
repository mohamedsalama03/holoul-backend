<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Documents\Data\DocumentFormat;
use App\Modules\Documents\Data\DocumentState;
use App\Modules\Documents\Data\StoredObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $customer_user_id
 * @property string $parent_id
 * @property string $uploader_id
 * @property string $reservation_input_hash
 * @property string $display_name
 * @property DocumentFormat $format
 * @property int $expected_size
 * @property string $expected_sha256
 * @property string $storage_key
 * @property string|null $storage_version
 * @property DocumentState $state
 * @property CarbonImmutable $upload_expires_at
 * @property CarbonImmutable|null $uploaded_at
 * @property string|null $scan_operation_id
 * @property string|null $delete_operation_id
 * @property int $scan_generation
 * @property int $manual_scan_retries
 * @property string|null $failure_code
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 */
final class Document extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['format' => DocumentFormat::class, 'state' => DocumentState::class,
            'expected_size' => 'integer', 'scan_generation' => 'integer', 'manual_scan_retries' => 'integer',
            'lock_version' => 'integer', 'upload_expires_at' => 'immutable_datetime',
            'uploaded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function object(): StoredObject
    {
        return new StoredObject($this->storage_key, $this->storage_version ?? throw new LogicException('Document has no object.'),
            $this->expected_size, $this->expected_sha256, $this->format->mime());
    }
}
