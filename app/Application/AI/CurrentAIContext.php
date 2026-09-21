<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Modules\AI\Contracts\AIContextVerifier;
use App\Modules\AI\Models\AIRun;
use App\Modules\Categories\Contracts\TaxonomyReader;
use App\Modules\Documents\Contracts\DocumentTextService;
use App\Modules\Documents\Data\DocumentTextSource;
use App\Modules\Documents\Exceptions\DocumentSourceUnavailable;
use App\Modules\Identity\Actions\ReadActiveIdentity;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class CurrentAIContext implements AIContextVerifier
{
    public function __construct(private ReadActiveIdentity $identities, private AISources $sources,
        private DocumentTextService $documents, private TaxonomyReader $taxonomy) {}

    public function verify(AIRun $run): void
    {
        $identity = $this->identities->locked($run->actor_id);
        try {
            $current = $this->sources->capture($identity, $run->parent_type, $run->parent_id, $run->purpose,
                $run->document_id, (string) Str::uuid7(), false);
        } catch (DocumentSourceUnavailable) {
            throw new HttpException(409, 'The AI source is unavailable.');
        }
        if ($current->customerId !== $run->customer_id || $current->sourceVersion !== $run->source_version
            || $current->sourceId !== $run->source_id || $current->sourceType !== $run->source_type
            || ! hash_equals($current->sourceHash, $run->source_hash)
            || $current->documentChecksum !== $run->document_checksum || $current->documentObjectVersion !== $run->document_object_version) {
            throw new HttpException(409, 'The AI source has changed.');
        }
    }

    public function input(AIRun $run): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Document extraction must run outside business transactions.');
        }
        $source = DB::transaction(function () use ($run): ?DocumentTextSource {
            $this->verify($run);
            if ($run->document_id === null) {
                return null;
            }

            return $this->sources->document($this->identities->locked($run->actor_id), $run->parent_type,
                $run->parent_id, $run->document_id, (string) Str::uuid7());
        });
        $text = $source === null ? $run->source_text : $this->documents->extract($source, Config::integer('ai.maximum_input_characters'));
        DB::transaction(fn () => $this->verify($run));

        return $text;
    }

    public function validateTaxonomy(string $categoryId, string $subcategoryId): void
    {
        $this->taxonomy->selection($categoryId, $subcategoryId, DB::transactionLevel() > 0);
    }
}
