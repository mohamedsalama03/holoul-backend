<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property string $customer_id
 * @property int $revision_number
 * @property string $full_name
 * @property string $email
 * @property string $phone_e164
 * @property string $category_id
 * @property string $subcategory_id
 * @property string $category_label
 * @property string $subcategory_label
 * @property string $project_name
 * @property string $project_description
 * @property bool $budget_unknown
 * @property ?int $budget_minor
 * @property ?string $currency
 * @property string $submitted_by
 * @property CarbonImmutable $submitted_at
 * @property string $provenance
 */
final class RequestRevision extends Model
{
    use HasUuids;

    protected $table = 'request_revisions';

    /** @var list<string> */
    protected $guarded = ['*'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision_number' => 'integer', 'budget_unknown' => 'boolean', 'budget_minor' => 'integer', 'submitted_at' => 'immutable_datetime'];
    }
}
