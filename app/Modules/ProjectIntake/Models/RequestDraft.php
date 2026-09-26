<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property ?string $customer_id
 * @property bool $is_open
 * @property int $base_revision_number
 * @property ?string $category_id
 * @property ?string $subcategory_id
 * @property ?string $project_name
 * @property ?string $project_description
 * @property ?bool $budget_unknown
 * @property ?int $budget_minor
 * @property ?string $currency
 */
final class RequestDraft extends Model
{
    use HasUuids;

    protected $table = 'request_drafts';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_open' => 'boolean', 'base_revision_number' => 'integer', 'budget_unknown' => 'boolean', 'budget_minor' => 'integer'];
    }
}
