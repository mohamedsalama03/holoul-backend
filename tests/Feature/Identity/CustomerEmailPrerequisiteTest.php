<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Infrastructure\Async\OperationRunner;
use App\Modules\AI\Models\AIRun;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CommercialDatabase;
use Tests\Support\IdentityHttp;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class CustomerEmailPrerequisiteTest extends TestCase
{
    use CommercialDatabase;
    use IdentityHttp;
    use IntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Config::set('ai.enabled', true);
        $this->initializeBrowser();
    }

    public function test_registration_login_and_intake_admission_do_not_require_or_fabricate_email_verification(): void
    {
        $this->browser('POST', '/api/v1/auth/register', ['full_name' => 'Synthetic Customer',
            'email' => 'new-customer@example.test', 'phone' => '+218912345678',
            'password' => 'Correct-Horse-72-River', 'password_confirmation' => 'Correct-Horse-72-River'])
            ->assertAccepted()->assertJsonPath('data.message', 'Registration request received. You can sign in with your credentials.');
        $user = User::query()->sole();
        self::assertNull($user->email_verified_at);
        $this->assertDatabaseCount('identity_recovery_tokens', 0);
        $this->assertDatabaseCount('identity_recovery_mail', 0);
        Queue::assertNothingPushed();
        $this->signIn($user)->assertOk()->assertJsonPath('data.next_step', 'authenticated');
        $me = $this->browser('GET', '/api/v1/identity/me')->assertOk()->assertJsonPath('data.email_verified', false);
        foreach (['project_requests.submit', 'project_requests.documents.upload', 'ai.request'] as $capability) {
            self::assertContains($capability, $me->json('data.capabilities'));
        }
        self::assertNotContains('admin.dashboard.view', $me->json('data.capabilities'));
        $this->browser('GET', '/api/v1/identity/staff')->assertForbidden();
        $created = $this->browser('POST', '/api/v1/project-requests', $this->intakeInput())->assertCreated();
        $this->browser('POST', '/api/v1/project-requests/'.$created->json('data.id').'/submissions', [],
            ['If-Match' => $created->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated();
        $this->assertDatabaseCount('request_revisions', 1);
        self::assertNull($user->refresh()->email_verified_at);
    }

    public function test_existing_unverified_customer_has_private_explicit_ai_assistance_and_foreign_account_is_denied(): void
    {
        $user = $this->intakeCustomer(false);
        $this->signIn($user)->assertOk();
        $created = $this->browser('POST', '/api/v1/project-requests', $this->intakeInput())->assertCreated();
        $id = $created->json('data.id');
        $original = DB::table('request_drafts')->where('request_id', $id)->value('project_description');
        $reply = $this->browser('POST', '/api/v1/ai-runs', ['parent_type' => 'request', 'parent_id' => $id,
            'purpose' => 'improve_description', 'consent' => true],
            ['If-Match' => $created->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertAccepted();
        $run = AIRun::query()->findOrFail($reply->json('data.id'));
        app(OperationRunner::class)->run($run->operation_id);
        $view = $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertOk()->assertJsonPath('data.state', 'succeeded');
        self::assertSame($original, DB::table('request_drafts')->where('request_id', $id)->value('project_description'));
        $this->browser('POST', '/api/v1/ai-runs/'.$run->id.'/applications', [],
            ['If-Match' => $view->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid7()])->assertOk();
        self::assertNull($user->refresh()->email_verified_at);
        $this->signIn($this->intakeCustomer(false))->assertOk();
        $this->browser('GET', '/api/v1/ai-runs/'.$run->id)->assertNotFound();
        $this->browser('GET', '/api/v1/project-requests/'.$id)->assertNotFound();
    }
}
