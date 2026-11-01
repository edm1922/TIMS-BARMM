<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportFailureDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    public function test_reports_page_explains_a_missing_batch_table(): void
    {
        Schema::drop('beneficiary_batches');

        $this->actingAs($this->staff())
            ->get('/reports')
            ->assertStatus(500)
            ->assertSee('table beneficiary_batches')
            ->assertSee('migrate --force');
    }

    public function test_a_failing_reports_page_is_recorded_for_the_central_server(): void
    {
        Schema::drop('beneficiary_batches');

        $this->actingAs($this->staff())->get('/reports')->assertStatus(500);

        $log = AuditLog::query()->where('action', 'report.error')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('index', $log->metadata['context']);
        $this->assertContains('table beneficiary_batches', $log->metadata['missing_schema']);
        $this->assertArrayHasKey('pending_migrations', $log->metadata);
        $this->assertArrayHasKey('php_version', $log->metadata);
    }

    public function test_a_failing_export_is_explained_and_recorded(): void
    {
        Schema::drop('beneficiaries');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/reports/export?format=csv')
            ->assertStatus(500)
            ->assertSee('could not be loaded');

        $log = AuditLog::query()->where('action', 'report.error')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('export', $log->metadata['context']);
        $this->assertArrayHasKey('message', $log->metadata);
    }
}
