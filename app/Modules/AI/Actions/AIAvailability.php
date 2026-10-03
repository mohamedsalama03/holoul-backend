<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use App\Infrastructure\Configuration\AIConfiguration;
use Illuminate\Support\Facades\Config;

final class AIAvailability
{
    public static function allows(string $purpose, string $sourceType, ?string $documentId): bool
    {
        if (! Config::boolean('ai.enabled') || ! AIConfiguration::approved()) {
            return false;
        }

        // External processing is limited to customer intake, never staff revisions
        // or delivery documents. Document analysis has its own deployment opt-in.
        return Config::string('ai.driver') === 'sandbox'
            || ($sourceType === 'intake_draft'
                && (($purpose === 'improve_description' && $documentId === null)
                    || ($purpose === 'analyze_document' && $documentId !== null
                        && Config::boolean('ai.gemini.documents_approved', false))));
    }
}
