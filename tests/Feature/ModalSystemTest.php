<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The shared modal & dialog design system: every existing modal is expected to
 * use the standard markup/classes while keeping its original fields and ids.
 */
class ModalSystemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_every_page_gets_the_shared_modal_styles_and_script(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('tims-modal--md', false)
            ->assertSee('data-tims-submitting', false)
            ->assertSee('is-loading', false);
    }

    public function test_the_create_batch_modal_keeps_its_fields_and_uses_the_standard_layout(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('beneficiaries.index'))->assertOk();

        $response
            ->assertSee('id="batchModal"', false)
            ->assertSee('Create batch')
            ->assertSee('id="batch-name"', false)
            ->assertSee('id="batch-description"', false)
            ->assertSee('id="batch-capacity"', false)
            ->assertSee('id="batch-code-series"', false)
            ->assertSee('id="batch-manager"', false)
            ->assertSee('The batch opens straight away.', false)
            ->assertSee('Maximum number of beneficiaries allowed in this batch.', false);
    }

    public function test_the_delete_beneficiary_modal_is_a_standard_confirmation_dialog(): void
    {
        $admin = $this->admin();
        $beneficiary = Beneficiary::factory()->create();

        $this->actingAs($admin)->get(route('beneficiaries.show', $beneficiary))
            ->assertOk()
            ->assertSee('id="deleteBeneficiaryModal"', false)
            ->assertSee('tims-modal--sm', false)
            ->assertSee('Delete permanently')
            ->assertSee('This cannot be undone')
            ->assertDontSee('id="confirm_code"', false)
            ->assertDontSee('id="delete_reason"', false);
    }

    public function test_the_audit_event_modal_uses_the_shared_information_dialog(): void
    {
        $admin = $this->admin();

        AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $admin->id,
            'occurred_at' => now(),
            'action' => 'beneficiary_batch.unassigned',
            'entity_type' => 'beneficiary_batch',
            'entity_id' => 3,
        ]);

        $this->actingAs($admin)->get('/audit')
            ->assertOk()
            ->assertSee('Audit event details')
            ->assertSee('tims-modal--lg', false)
            ->assertSee('Read-only record from the immutable audit trail.');
    }

    public function test_the_duplicate_verification_modal_keeps_its_fields(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();

        $this->actingAs($admin)->get(route('beneficiary-batches.beneficiaries.create', $batch))
            ->assertOk()
            ->assertSee('id="duplicateCheckModal"', false)
            ->assertSee('Verify beneficiary')
            ->assertSee('id="dup_first_name"', false)
            ->assertSee('id="dup_last_name"', false)
            ->assertSee('id="dup_birthdate"', false);
    }

    public function test_the_enrollment_package_modal_keeps_its_form_and_actions(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('enroll-server.index'))
            ->assertOk()
            ->assertSee('id="buildPackageModal"', false)
            ->assertSee('Build installation package')
            ->assertSee('Confirm build')
            ->assertSee('id="confirm-build-btn"', false)
            ->assertSee('id="build-package-form"', false)
            ->assertSee('name="action" value="publish"', false)
            ->assertSee('Build and publish a new offline installation package?')
            ->assertDontSee('id="modal_device_label"', false)
            ->assertDontSee('id="modal_note"', false)
            ->assertDontSee('id="download-btn"', false)
            ->assertDontSee('id="publish-btn"', false);
    }
}
