<?php

declare(strict_types=1);

namespace Tests\Feature\Assistance;

use App\Infrastructure\Async\RunOperationJob;
use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\CommercialDatabase;
use Tests\Support\ProjectFixtures;
use Tests\TestCase;

final class BusinessNotificationsTest extends TestCase
{
    use CommercialDatabase;
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_business_commit_and_rollback_include_notification_and_durable_intent_atomically(): void
    {
        $user = $this->intakeCustomer();
        $actor = $this->intakeActor($user);
        $request = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        DB::beginTransaction();
        app(SubmitRequest::class)->handle($actor, $request->id, $this->commercialEtag($request), (string) Str::uuid7(), (string) Str::uuid7());
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        self::assertSame(1, DB::table('async_operations')->where('kind', 'notifications.email')->count());
        Queue::assertNothingPushed();
        DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('intake_notification_intents', 0);
        self::assertSame('draft', $request->fresh()->state->value);
        $key = (string) Str::uuid7();
        $etag = $this->commercialEtag($request);
        app(SubmitRequest::class)->handle($actor, $request->id, $etag, $key, (string) Str::uuid7());
        app(SubmitRequest::class)->handle($actor, $request->id, $etag, $key, (string) Str::uuid7());
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('intake_notification_intents', 1);
        self::assertSame(1, DB::table('async_operations')->where('kind', 'notifications.email')->count());
        Queue::assertPushed(RunOperationJob::class);
    }

    public function test_commercial_and_conversion_events_deliver_only_safe_fixed_content(): void
    {
        $fixture = $this->projectFixture();
        $customer = $fixture['customer']->id;
        foreach (['request.submitted', 'proposal.issued', 'proposal.accepted', 'project.created'] as $type) {
            self::assertSame(1, DB::table('notifications')->where('recipient_id', $customer)->where('type', $type)->count());
        }
        self::assertSame(1, DB::table('notifications')->where('recipient_id', $fixture['author']->id)->where('type', 'proposal.accepted')->count());
        foreach (DB::table('notifications')->get() as $notice) {
            self::assertSame('Sign in to HOLOUL to review this update.', $notice->message);
            self::assertStringNotContainsString('Private', $notice->title.$notice->message);
            self::assertStringNotContainsString('30.369', $notice->title.$notice->message);
            self::assertStringNotContainsString('customer@example', strtolower($notice->title.$notice->message));
        }
        self::assertSame(DB::table('notifications')->count(), DB::table('notification_deliveries')->count());
    }

    public function test_internal_milestones_do_not_notify_customer_and_public_updates_notify_once(): void
    {
        $fixture = $this->projectFixture();
        $project = $fixture['project'];
        foreach ([false, true] as $public) {
            $result = $this->projectCommand($fixture['author'], $project, 'project.milestone.create', input: [
                'name' => 'Private or public delivery milestone.', 'display_order' => $public ? 2 : 1, 'customer_visible' => $public,
            ]);
            $this->projectCommand($fixture['author'], $project, 'project.milestone.start', $result['data']['id']);
            self::assertSame($public ? 1 : 0, DB::table('notifications')->where('type', 'project.milestone_updated')->count());
        }
        $project->refresh();
        $etag = VersionPrecondition::etag($project->id, $project->lock_version);
        $key = (string) Str::uuid7();
        foreach ([1, 2] as $_) {
            $this->projectCommand($fixture['author'], $project, 'project.update.publish', input: ['content' => 'Private body never enters email.'], etag: $etag, key: $key);
        }
        self::assertSame(1, DB::table('notifications')->where('type', 'project.update_published')->count());
        self::assertSame(1, DB::table('project_updates')->where('content', 'Private body never enters email.')->count());
    }
}
