<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\User;
use App\Services\Reports\BeneficiaryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/reports')->assertRedirect('/login');
        $this->get('/reports/export?format=csv')->assertRedirect('/login');
        $this->get('/audit')->assertRedirect('/login');
    }

    public function test_staff_can_view_reports_but_not_export(): void
    {
        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')->create();

        $staff = $this->staff();

        $this->actingAs($staff)->get('/reports')
            ->assertOk()
            ->assertSee('ADA ADAM TESTER')
            ->assertDontSee('Export CSV');

        $this->actingAs($staff)->get('/reports/export?format=csv')->assertForbidden();
        $this->actingAs($staff)->get('/reports/export?format=xlsx')->assertForbidden();
    }

    public function test_report_filters_underage_records(): void
    {
        $underage = Beneficiary::factory()->named('UNA', 'UNDER', 'AGE')->underage()->create();
        $adult = Beneficiary::factory()->named('ZORRO', 'ADULT', 'PERSON')->withBirthdate('1980-01-01')->create();

        $this->actingAs($this->staff())
            ->get('/reports?age_status=underage')
            ->assertOk()
            ->assertSee($underage->full_name)
            ->assertDontSee($adult->full_name);
    }

    public function test_csv_export_is_bom_prefixed_and_formula_safe(): void
    {
        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')->withBirthdate('1990-05-05')->create([
            'notes' => '=SUM(A1:A3)',
        ]);
        Beneficiary::factory()->named('BRAVO', 'BEN', 'CARD')->withBirthdate('1985-01-02')->create();
        Beneficiary::factory()->named('OLD', 'ARCHIVED', 'RECORD')->create(['archived_at' => now()]);

        $response = $this->actingAs($this->admin())->get('/reports/export?format=csv');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertCount(3, $lines, 'active filter exports header + 2 active records');
        $this->assertSame(BeneficiaryReport::headers(), str_getcsv($lines[0]));
        $this->assertStringContainsString("'=SUM(A1:A3)", $csv, 'formula is neutralised with an apostrophe');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'export.csv',
            'user_id' => auth()->id(),
        ]);
        $log = AuditLog::query()->where('action', 'export.csv')->firstOrFail();
        $this->assertSame(2, $log->metadata['count']);
        $this->assertNull($log->entity_id);
    }

    public function test_export_respects_filters_and_records_count(): void
    {
        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')->create();
        Beneficiary::factory()->named('BRAVO', 'BEN', 'CARD')->create();

        $this->actingAs($this->admin())->get('/reports/export?format=csv&q=ADA')
            ->assertOk();

        $log = AuditLog::query()->where('action', 'export.csv')->firstOrFail();
        $this->assertSame(1, $log->metadata['count']);
        $this->assertSame('ADA', $log->metadata['filters']['q']);
    }

    public function test_xlsx_export_returns_a_workbook_and_is_audited(): void
    {
        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')->create();

        $response = $this->actingAs($this->admin())->get('/reports/export?format=xlsx');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);
        $this->assertSame('PK', substr((string) file_get_contents($file), 0, 2), 'xlsx is a zip container');

        $log = AuditLog::query()->where('action', 'export.xlsx')->firstOrFail();
        $this->assertSame(1, $log->metadata['count']);
    }

    public function test_unknown_export_format_is_rejected(): void
    {
        $this->actingAs($this->admin())->get('/reports/export?format=pdf')->assertNotFound();
    }

    public function test_audit_log_is_admin_only_and_filterable(): void
    {
        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')->create();

        $admin = $this->admin();
        $this->actingAs($admin)->get('/reports/export?format=csv')->assertOk();

        $this->actingAs($this->staff())->get('/audit')->assertForbidden();

        $this->actingAs($admin)->get('/audit')
            ->assertOk()
            ->assertSee('export.csv')
            ->assertSee($admin->name);

        $this->actingAs($admin)->get('/audit?action=export.csv')
            ->assertOk()
            ->assertSee('export.csv');

        $this->actingAs($admin)->get('/audit?action=id_card.printed')
            ->assertOk()
            ->assertSee('No audit entries match these filters.');
    }
}
