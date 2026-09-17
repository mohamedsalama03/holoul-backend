<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Http\Resources;

use App\Infrastructure\Money\Money;
use App\Modules\ProjectIntake\Models\RequestRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RequestRevisionResource extends JsonResource
{
    public function __construct(private readonly RequestRevision $revision, private readonly ?string $documentId = null)
    {
        parent::__construct($revision);
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->revision;

        return ['id' => $row->id, 'revision_number' => $row->revision_number,
            'document_id' => $this->documentId,
            'full_name' => $row->full_name, 'email' => $row->email, 'phone' => $row->phone_e164,
            'category_id' => $row->category_id, 'subcategory_id' => $row->subcategory_id,
            'category_label' => $row->category_label, 'subcategory_label' => $row->subcategory_label,
            'project_name' => $row->project_name, 'project_description' => $row->project_description,
            'budget_unknown' => $row->budget_unknown,
            'estimated_budget' => $row->budget_minor !== null && $row->currency !== null ? Money::fromMinorUnits($row->budget_minor, $row->currency)->decimal() : null,
            'currency' => $row->currency, 'submitted_at' => $row->submitted_at->utc()->toISOString(),
            'submitted_by' => $row->submitted_by, 'provenance' => $row->provenance];
    }
}
