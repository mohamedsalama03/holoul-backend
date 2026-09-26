<?php

declare(strict_types=1);

namespace Tests\Feature\Categories;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\IdentityHttp;
use Tests\Support\IntakeFixtures;
use Tests\TestCase;

final class TaxonomyHttpValidationTest extends TestCase
{
    use DatabaseMigrations;
    use IdentityHttp;
    use IntakeFixtures;

    public function test_invalid_cursor_errors_identify_the_public_query_parameter(): void
    {
        $this->initializeBrowser();
        $this->signIn($this->intakeStaff('administrator'))->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        $category = $this->browser('POST', '/api/v1/admin/categories',
            ['name' => 'Cursor category', 'slug' => 'cursor-category', 'active' => true, 'display_order' => 0])->assertCreated();
        $categoryId = $category->json('data.id');

        foreach (['bad', str_repeat('a', 201)] as $cursor) {
            foreach (['/api/v1/categories', '/api/v1/admin/categories',
                '/api/v1/categories/'.$categoryId.'/subcategories', '/api/v1/admin/categories/'.$categoryId.'/subcategories'] as $path) {
                $this->browser('GET', $path.'?cursor='.$cursor)->assertUnprocessable()
                    ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                    ->assertJsonPath('error.fields.cursor', ['This field is invalid.'])
                    ->assertJsonMissingPath('error.fields.after');
            }
        }
    }

    public function test_reorder_requires_native_integer_without_partially_applying_other_fields(): void
    {
        $this->initializeBrowser();
        $this->signIn($this->intakeStaff('administrator'))->assertAccepted();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment')->assertOk()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/enrollment/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();
        $category = $this->browser('POST', '/api/v1/admin/categories',
            ['name' => 'Original category', 'slug' => 'original', 'active' => true, 'display_order' => 0])->assertCreated();
        $categoryId = $category->json('data.id');
        $subcategory = $this->browser('POST', '/api/v1/admin/categories/'.$categoryId.'/subcategories',
            ['name' => 'Original child', 'slug' => 'child', 'active' => true, 'display_order' => 0])->assertCreated();
        $subcategoryId = $subcategory->json('data.id');

        foreach (['7', 7.5, true, null] as $invalid) {
            foreach (['/api/v1/admin/categories', '/api/v1/admin/categories/'.$categoryId.'/subcategories'] as $path) {
                $this->browser('POST', $path, ['name' => 'Rejected creation', 'slug' => 'rejected', 'active' => true, 'display_order' => $invalid])
                    ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
            }
            foreach ([['/api/v1/admin/categories/'.$categoryId, $category], ['/api/v1/admin/subcategories/'.$subcategoryId, $subcategory]] as [$path, $original]) {
                $this->browser('PATCH', $path, ['name' => 'Must not partially rename', 'display_order' => $invalid], ['If-Match' => $original->headers->get('ETag')])
                    ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
            }
        }
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('subcategories', 1);
        $this->assertDatabaseHas('categories', ['id' => $categoryId, 'name' => 'Original category', 'display_order' => 0, 'lock_version' => 1]);
        $this->assertDatabaseHas('subcategories', ['id' => $subcategoryId, 'name' => 'Original child', 'display_order' => 0, 'lock_version' => 1]);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'taxonomy.category.updated']);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'taxonomy.subcategory.updated']);

        $this->browser('PATCH', '/api/v1/admin/categories/'.$categoryId, ['display_order' => 7], ['If-Match' => $category->headers->get('ETag')])
            ->assertOk()->assertHeaderMissing('Location')->assertJsonPath('data.display_order', 7)->assertJsonPath('data.lock_version', 2);
        $this->browser('PATCH', '/api/v1/admin/subcategories/'.$subcategoryId, ['display_order' => 7], ['If-Match' => $subcategory->headers->get('ETag')])
            ->assertOk()->assertHeaderMissing('Location')->assertJsonPath('data.display_order', 7)->assertJsonPath('data.lock_version', 2);
        $this->browser('GET', '/api/v1/admin/categories?limit=1')->assertOk()->assertJsonCount(1, 'data');
    }
}
