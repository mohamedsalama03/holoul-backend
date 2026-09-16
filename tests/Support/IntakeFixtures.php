<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Http\VersionPrecondition;
use App\Modules\Categories\Actions\ManageTaxonomy;
use App\Modules\Categories\Data\TaxonomyActor;
use App\Modules\Customers\Actions\CreateCustomer;
use App\Modules\Identity\Authorization\Actions\AssignCustomerRole;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\RoleAuthority;
use App\Modules\Identity\Models\User;
use App\Modules\ProjectIntake\Actions\ManageDraft;
use App\Modules\ProjectIntake\Actions\SubmitRequest;
use App\Modules\ProjectIntake\Data\IntakeActor;
use App\Modules\ProjectIntake\Models\ProjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait IntakeFixtures
{
    private function intakeCustomer(bool $verified = true): User
    {
        $user = User::query()->create(['full_name' => 'عميل Test', 'email' => Str::uuid7().'@example.test',
            'email_display' => 'Customer@example.test', 'password' => 'Correct-Horse-72-River', 'kind' => 'customer',
            'enabled' => true, 'auth_version' => 1, 'email_verified_at' => $verified ? now() : null]);
        app(CreateCustomer::class)->handle($user->id, '+218912345678', '+218912345678');
        app(AssignCustomerRole::class)->handle($user->id, (string) Str::uuid7());

        return $user;
    }

    private function intakeStaff(string $role = 'project_manager'): User
    {
        $user = User::query()->create(['full_name' => 'Intake Staff', 'email' => Str::uuid7().'@example.test',
            'email_display' => 'Staff@example.test', 'password' => 'Correct-Horse-72-River', 'kind' => 'staff',
            'enabled' => true, 'auth_version' => 1, 'email_verified_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => DB::table('roles')->where('code', $role)->value('id'), 'user_kind' => 'staff']);

        return $user;
    }

    private function intakeActor(User $user): IntakeActor
    {
        $permissions = [];
        foreach (Permission::cases() as $permission) {
            if (app(RoleAuthority::class)->allows($user, $permission)) {
                $permissions[] = $permission->value;
            }
        }

        return new IntakeActor($user->id, $user->kind === 'customer' ? DB::table('customers')->where('user_id', $user->id)->value('id') : null,
            $user->email_verified_at !== null, $permissions);
    }

    /** @return array{category_id:string,subcategory_id:string} */
    private function intakeTaxonomy(): array
    {
        $actor = new TaxonomyActor((string) Str::uuid7(), true);
        $category = app(ManageTaxonomy::class)->createCategory($actor, 'Software برمجيات', 'software-'.Str::lower(Str::random(8)), true, 0, (string) Str::uuid7());
        $subcategory = app(ManageTaxonomy::class)->createSubcategory($actor, $category->id, 'Web تطبيقات', 'web-'.Str::lower(Str::random(8)), true, 0, (string) Str::uuid7());

        return ['category_id' => $category->id, 'subcategory_id' => $subcategory->id];
    }

    /** @return array<string,mixed> */
    private function intakeInput(): array
    {
        return [...$this->intakeTaxonomy(), 'project_name' => 'منصة تعليم Learning portal',
            'project_description' => 'Private description for a bilingual learning platform.',
            'budget_unknown' => false, 'estimated_budget' => '1234.567', 'currency' => 'LYD'];
    }

    private function createSubmitted(User $user): ProjectRequest
    {
        $actor = $this->intakeActor($user);
        $record = app(ManageDraft::class)->create($actor, $this->intakeInput(), (string) Str::uuid7());
        app(SubmitRequest::class)->handle($actor, $record->id, VersionPrecondition::etag($record->id, $record->lock_version), (string) Str::uuid7(), (string) Str::uuid7());

        return $record->fresh();
    }
}
