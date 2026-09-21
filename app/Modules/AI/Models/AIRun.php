<?php

declare(strict_types=1);

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $actor_id
 * @property string $customer_id
 * @property string $parent_type
 * @property string $parent_id
 * @property string $source_type
 * @property string $source_id
 * @property int $source_version
 * @property string $source_hash
 * @property string $source_text
 * @property string $input_hash
 * @property string $requested_correlation_id
 * @property ?string $document_id
 * @property ?string $document_checksum
 * @property ?string $document_object_version
 * @property list<array{category_id:string,subcategory_id:string,category_name:string,subcategory_name:string}> $taxonomy
 * @property string $purpose
 * @property string $provider
 * @property string $model
 * @property string $state
 * @property int $lock_version
 * @property string $budget_day
 * @property int $reserved_cost_microusd
 * @property string $reservation_state
 * @property ?string $operation_id
 * @property ?int $dispatch_fence
 * @property ?string $provider_operation_id
 * @property ?string $failure_code
 * @property ?int $actual_cost_microusd
 */
final class AIRun extends Model
{
    use HasUuids;

    protected $table = 'ai_runs';

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['source_version' => 'integer', 'lock_version' => 'integer', 'taxonomy' => 'array', 'reserved_cost_microusd' => 'integer',
            'dispatch_fence' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'actual_cost_microusd' => 'integer'];
    }
}
