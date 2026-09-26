<?php

declare(strict_types=1);

namespace Tests\Feature\ProjectIntake;

use App\Modules\Customers\Contracts\CustomerContactReader;
use App\Modules\Documents\Actions\ClaimGuestDocuments;
use App\Modules\Documents\Contracts\DocumentService;
use App\Modules\Documents\Data\DocumentOwner;
use App\Modules\ProjectIntake\Actions\ClaimGuestRequest;
use App\Modules\ProjectIntake\Actions\GuestDrafts;
use App\Modules\ProjectIntake\Actions\SubmitGuestRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommercialDatabase;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class GuestIntakeConstraintsTest extends TestCase
{
    use CommercialDatabase;
    use IntakeFixtures;

    public function test_customer_constraints_and_immutable_history_survive_the_nullable_guest_extension(): void
    {
        $user = $this->intakeCustomer();
        $other = $this->intakeCustomer();
        $record = $this->createSubmitted($user);
        $this->rejected(fn () => DB::table('project_requests')->where('id', $record->id)->update(['customer_id' => null, 'customer_user_id' => null]), '23514');
        $this->rejected(fn () => DB::table('project_requests')->where('id', $record->id)->update(['guest_origin' => true]), '23514');
        $this->rejected(fn () => DB::table('project_requests')->insert(['id' => (string) Str::uuid7(), 'customer_id' => null, 'customer_user_id' => null]), '23514');
        $this->rejected(fn () => DB::table('project_requests')->insert(['id' => (string) Str::uuid7(), 'guest_origin' => true,
            'customer_id' => $record->customer_id, 'customer_user_id' => $user->id]), '23514');
        $this->rejected(fn () => DB::table('project_requests')->insert(['id' => (string) Str::uuid7(),
            'customer_id' => $record->customer_id, 'customer_user_id' => $other->id]), '23503');
        $this->rejected(fn () => DB::table('request_drafts')->where('request_id', $record->id)->update(['customer_id' => null]), '23514');
        $this->rejected(fn () => DB::table('request_revisions')->where('request_id', $record->id)->update(['full_name' => 'Rewrite']), '42501');
    }

    public function test_guest_cannot_be_associated_without_a_same_transaction_claim_receipt_or_reassigned_after_claim(): void
    {
        $user = $this->intakeCustomer();
        $contact = app(CustomerContactReader::class)->currentForIdentity($user->id);
        $draft = app(GuestDrafts::class)->create('fixture-browser-binding', (string) Str::uuid7());
        $id = $draft['draft_id'];
        $receipt = app(SubmitGuestRequest::class)->handle($id, $draft['capability'], 'fixture-browser-binding', $draft['etag'], (string) Str::uuid7(),
            [...$this->intakeInput(), 'full_name' => 'Guest Original', 'email' => $user->email, 'phone' => '+218912345678'], (string) Str::uuid7());
        $original = (array) DB::table('request_revisions')->where('request_id', $id)->first();
        $this->rejected(fn () => DB::table('project_requests')->where('id', $id)->update(['customer_id' => $contact->customerId, 'customer_user_id' => $user->id]), '23514');
        $this->rejected(fn () => DB::table('intake_guest_claims')->insert(['request_id' => $id, 'customer_id' => $contact->customerId,
            'customer_user_id' => $user->id, 'token_hash' => hash('sha256', $receipt['claim_token']), 'key_hash' => str_repeat('b', 64), 'result_version' => 3]), '23514');
        DB::transaction(function () use ($contact, $receipt, $id): void {
            $correlation = (string) Str::uuid7();
            app(ClaimGuestRequest::class)->handle($contact, $receipt['claim_token'], (string) Str::uuid7(), $correlation);
            app(ClaimGuestDocuments::class)->handle($id, $contact->customerId, $contact->userId, $correlation);
        });
        self::assertSame($original, (array) DB::table('request_revisions')->where('request_id', $id)->first());
        $this->rejected(fn () => DB::table('request_drafts')->insert(['id' => (string) Str::uuid7(), 'request_id' => $id, 'customer_id' => null]), '23514');
        $document = DB::transaction(fn () => app(DocumentService::class)->reserve(new DocumentOwner($id, $contact->customerId, $user->id, $user->id, (string) Str::uuid7()),
            'after-claim.pdf', 10, str_repeat('a', 64), (string) Str::uuid7()));
        $this->rejected(fn () => DB::table('intake_draft_documents')->insert(['draft_id' => DB::table('request_drafts')->where('request_id', $id)->value('id'),
            'request_id' => $id, 'customer_id' => null, 'document_id' => $document->documentId]), '23514');
        $this->rejected(fn () => DB::table('intake_revision_documents')->insert(['revision_id' => $original['id'],
            'request_id' => $id, 'customer_id' => null, 'document_id' => $document->documentId]), '23514');
        $this->rejected(fn () => DB::table('project_requests')->where('id', $id)->update(['customer_id' => null, 'customer_user_id' => null]), '23514');
        $this->rejected(fn () => DB::table('intake_guest_claims')->where('request_id', $id)->delete(), '42501');
        $this->rejected(fn () => DB::table('intake_guest_claims')->where('request_id', $id)->update(['key_hash' => str_repeat('c', 64)]), '42501');
        $this->rejected(fn () => DB::table('request_revisions')->where('request_id', $id)->delete(), '42501');
    }

    public function test_null_document_ownership_is_impossible_outside_a_canonical_guest_parent(): void
    {
        $owner = $this->intakeCustomer();
        $record = $this->createSubmitted($owner);
        $id = (string) Str::uuid7();
        $document = ['id' => $id, 'customer_id' => null, 'customer_user_id' => null, 'uploader_id' => null, 'parent_id' => $record->id,
            'guest_request_id' => $record->id, 'reservation_key_hash' => str_repeat('a', 64), 'reservation_input_hash' => str_repeat('b', 64),
            'display_name' => 'idea.pdf', 'format' => 'pdf', 'expected_size' => 10, 'expected_sha256' => str_repeat('c', 64),
            'storage_key' => 'quarantine/'.$id, 'upload_expires_at' => now()->addMinutes(10)];
        $this->rejected(fn () => DB::table('documents')->insert($document), '23514');
        $this->rejected(fn () => DB::table('documents')->insert([...$document, 'guest_request_id' => null]), '23514');
    }

    private function rejected(callable $write, string $state): void
    {
        try {
            DB::transaction(function () use ($write): void {
                DB::statement('SET LOCAL ROLE holoul_app');
                $write();
            });
            self::fail('Runtime PostgreSQL role accepted an invalid mutation.');
        } catch (\PDOException $failure) {
            self::assertSame($state, $failure->getCode());
        }
    }
}
