<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use App\Services\Reports\BeneficiaryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $response->assertDownload('TUPAD PROFILE OF BENEFICIARIES-TEST CITY BARMM (2).csv');

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

    public function test_reports_can_be_filtered_by_batch(): void
    {
        $first = BeneficiaryBatch::factory()->named('DATU PAGLAS (100)')->create();
        $second = BeneficiaryBatch::factory()->named('COTABATO CITY (50)')->create();

        $member = Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')
            ->create(['beneficiary_batch_id' => $first->id]);
        $other = Beneficiary::factory()->named('BRAVO', 'BEN', 'CARD')
            ->create(['beneficiary_batch_id' => $second->id]);
        $loose = Beneficiary::factory()->named('CHARLIE', 'CHUCK', 'FREE')->create();

        $staff = $this->staff();

        $this->actingAs($staff)->get('/reports?batch='.$first->id)
            ->assertOk()
            ->assertSee('DATU PAGLAS (100) (1)')
            ->assertSee($member->full_name)
            ->assertDontSee($other->full_name)
            ->assertDontSee($loose->full_name);

        $this->actingAs($staff)->get('/reports?batch=unassigned')
            ->assertOk()
            ->assertSee($loose->full_name)
            ->assertDontSee($member->full_name)
            ->assertDontSee($other->full_name);
    }

    public function test_export_respects_the_batch_filter(): void
    {
        $batch = BeneficiaryBatch::factory()->named('DATU PAGLAS (100)')->create();

        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')
            ->create(['beneficiary_batch_id' => $batch->id]);
        Beneficiary::factory()->named('BRAVO', 'BEN', 'CARD')->create();

        $this->actingAs($this->admin())
            ->get('/reports/export?format=csv&batch='.$batch->id)
            ->assertOk();

        $log = AuditLog::query()->where('action', 'export.csv')->firstOrFail();

        $this->assertSame(1, $log->metadata['count']);
        $this->assertSame((string) $batch->id, $log->metadata['filters']['batch']);
    }

    public function test_export_filename_uses_the_batch_area_when_members_span_municipalities(): void
    {
        $batch = BeneficiaryBatch::factory()
            ->named('DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)')
            ->create();

        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')
            ->create(['beneficiary_batch_id' => $batch->id, 'city_municipality' => 'DATU PAGLAS']);
        Beneficiary::factory()->named('BRAVO', 'BEN', 'CARD')
            ->create(['beneficiary_batch_id' => $batch->id, 'city_municipality' => 'PAGLAT']);

        $response = $this->actingAs($this->admin())
            ->get('/reports/export?format=xlsx&batch='.$batch->id);

        $response->assertOk()
            ->assertDownload('TUPAD PROFILE OF BENEFICIARIES-DATU PAGLAS BARMM (2).xlsx');

        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);

        $this->assertSame('Municipality: DATU PAGLAS', $sheet->getCell('C9')->getValue());
    }

    public function test_an_unknown_batch_filter_is_ignored(): void
    {
        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')->create();

        $this->actingAs($this->staff())
            ->get('/reports?batch=not-a-batch')
            ->assertOk()
            ->assertSee('ADA ADAM TESTER')
            ->assertSee('All batches');
    }

    public function test_xlsx_export_matches_the_annex_d_profile_layout(): void
    {
        $beneficiary = Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')
            ->withBirthdate('1990-05-05')
            ->create([
                'barangay' => 'KALIPAPA',
                'city_municipality' => 'DATU PIANG',
                'province' => 'MAGUINDANAO DEL SUR',
                'cos_no' => '601',
                'source_row_no' => 32,
                'id_type' => "VOTER'S ID",
                'source_id_no' => '88070006AG91KPS10000',
                'contact_no' => '09513894359',
                'mode_of_payment' => 'BANK',
                'beneficiary_type' => 'UNDEREMPLOYED/SELF-EMPLOYED WORKERS',
                'occupation' => 'FARMER',
                'sex' => 'MALE',
                'civil_status' => 'MARRIED',
                'avg_monthly_income' => 4000,
                'dependent_name' => 'NASRIA S. KAMPOA',
                'employment_interest' => 'NO',
                'skills_training_needed' => '=1+1',
            ]);

        $response = $this->actingAs($this->admin())->get('/reports/export?format=xlsx');

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $response->assertDownload('TUPAD PROFILE OF BENEFICIARIES-DATU PIANG BARMM (1).xlsx');

        $file = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($file);
        $this->assertSame('PK', substr((string) file_get_contents($file), 0, 2), 'xlsx is a zip container');

        $sheet = IOFactory::load($file)->getSheet(0);

        $this->assertSame('Sheet1', $sheet->getTitle());
        $this->assertSame('Annex D', $sheet->getCell('C1')->getValue());
        $this->assertSame('Profile of TUPAD Beneficiaries', $sheet->getCell('C3')->getValue());
        $this->assertSame('DOLE Regional Office: XII', $sheet->getCell('C7')->getValue());
        $this->assertSame('Province: MAGUINDANAO DEL SUR', $sheet->getCell('C8')->getValue());
        $this->assertSame('Municipality: DATU PIANG', $sheet->getCell('C9')->getValue());
        $this->assertSame('Barangay: KALIPAPA', $sheet->getCell('C10')->getValue());

        $this->assertSame('COS NUMBER', $sheet->getCell('B13')->getValue());
        $this->assertSame('Name of Beneficiary', $sheet->getCell('D13')->getValue());
        $this->assertSame('First Name', $sheet->getCell('D14')->getValue());
        $this->assertSame('Project Location', $sheet->getCell('I13')->getValue());
        $this->assertSame('Province', $sheet->getCell('K14')->getValue());
        $this->assertSame('(YYYY/MM/DD)', $sheet->getCell('H14')->getValue());
        $this->assertSame('ID NUMBER', $sheet->getCell('A14')->getValue());
        $this->assertSame('Mode of payment', $sheet->getCell('O13')->getValue());
        $this->assertContains('D13:G13', $sheet->getMergeCells());
        $this->assertContains('I13:K13', $sheet->getMergeCells());

        $expected = [
            'A15' => $beneficiary->beneficiary_code,
            'B15' => '601',
            'C15' => '32',
            'D15' => 'ADA',
            'E15' => 'ADAM',
            'F15' => 'TESTER',
            'H15' => '1990/05/05',
            'I15' => 'KALIPAPA',
            'J15' => 'DATU PIANG',
            'K15' => 'MAGUINDANAO DEL SUR',
            'L15' => "VOTER'S ID",
            'M15' => '88070006AG91KPS10000',
            'N15' => '09513894359',
            'O15' => 'BANK',
            'P15' => 'UNDEREMPLOYED/SELF-EMPLOYED WORKERS',
            'Q15' => 'FARMER',
            'R15' => 'MALE',
            'S15' => 'MARRIED',
            'T15' => (string) $beneficiary->computedAge(),
            'U15' => '4000',
            'V15' => 'NASRIA S. KAMPOA',
            'W15' => 'NO',
            'X15' => '=1+1',
        ];

        foreach ($expected as $cell => $value) {
            $this->assertSame($value, $sheet->getCell($cell)->getValue(), $cell);
        }

        $this->assertSame('s', $sheet->getCell('X15')->getDataType(), 'data cells stay explicit strings');
        $this->assertNull($sheet->getCell('Y15')->getValue(), 'the District and E-payment columns no longer exist');
        $this->assertNull($sheet->getCell('Y13')->getValue());

        $log = AuditLog::query()->where('action', 'export.xlsx')->firstOrFail();
        $this->assertSame(1, $log->metadata['count']);
    }

    public function test_xlsx_masthead_omits_location_when_the_export_spans_areas(): void
    {
        Beneficiary::factory()->named('ADA', 'ADAM', 'TESTER')
            ->create(['province' => 'MAGUINDANAO DEL SUR', 'city_municipality' => 'DATU PIANG']);
        Beneficiary::factory()->named('BRAVO', 'BEN', 'CARD')
            ->create(['province' => 'COTABATO', 'city_municipality' => 'COTABATO CITY']);

        $response = $this->actingAs($this->admin())->get('/reports/export?format=xlsx');
        $response->assertOk();
        $response->assertDownload('TUPAD PROFILE OF BENEFICIARIES (2).xlsx');

        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheet(0);

        $this->assertSame('Province: ', $sheet->getCell('C8')->getValue());
        $this->assertSame('Municipality: ', $sheet->getCell('C9')->getValue());
        $this->assertSame('Barangay: ', $sheet->getCell('C10')->getValue());
        $this->assertNotSame('', $sheet->getCell('A16')->getValue(), 'second record is exported');
    }

    public function test_export_filename_follows_the_dole_convention(): void
    {
        $this->assertSame(
            'TUPAD PROFILE OF BENEFICIARIES-DATU PAGLAS BARMM (83).xlsx',
            BeneficiaryReport::exportFilename(['municipality' => 'Datu Paglas'], 83, 'xlsx'),
        );

        $this->assertSame(
            'TUPAD PROFILE OF BENEFICIARIES (1283).xlsx',
            BeneficiaryReport::exportFilename(['municipality' => null], 1283, 'xlsx'),
        );

        $this->assertSame(
            'TUPAD PROFILE OF BENEFICIARIES-A B BARMM (2).csv',
            BeneficiaryReport::exportFilename(['municipality' => 'A/B'], 2, 'csv'),
        );

        $this->assertSame(
            'TUPAD PROFILE OF BENEFICIARIES-DATU PAGLAS BARMM (87).xlsx',
            BeneficiaryReport::exportFilename(
                ['municipality' => null],
                87,
                'xlsx',
                'DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)',
            ),
        );

        $this->assertSame(
            'TUPAD PROFILE OF BENEFICIARIES-PAHAMUDDIN BARMM (1000).xlsx',
            BeneficiaryReport::exportFilename(
                ['municipality' => null],
                1000,
                'xlsx',
                'PROFILE OF TUPAD BENEFICIARIES-PAHAMUDDIN, SGA, BARMM (1,000)',
            ),
        );

        $this->assertSame(
            'TUPAD PROFILE OF BENEFICIARIES-DATU PIANG BARMM (5).xlsx',
            BeneficiaryReport::exportFilename(
                ['municipality' => 'DATU PIANG'],
                5,
                'xlsx',
                'DATU PAGLAS, MAGUINDANAO DEL SUR BARMM (100)',
            ),
            'the exported rows win over the batch name',
        );
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
            ->assertSee('Exported a CSV')
            ->assertSee($admin->name);

        $this->actingAs($admin)->get('/audit?action=export.csv')
            ->assertOk()
            ->assertSee('Exported a CSV');

        $this->actingAs($admin)->get('/audit?action=id_card.printed')
            ->assertOk()
            ->assertSee('No audit entries match these filters.');
    }

    public function test_the_reports_page_uses_the_shared_reporting_layout(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Reports')
            ->assertSee('Search &amp; filter beneficiaries', false)
            ->assertSee('Beneficiary records')
            ->assertSee('Total records')
            ->assertSee('Duplicate candidates')
            ->assertSee('Export CSV')
            ->assertSee('Export XLSX')
            ->assertSee('id="q"', false)
            ->assertSee('name="created_from"', false)
            ->assertSee('name="duplicate_status"', false);
    }

    public function test_the_reports_filters_match_the_beneficiaries_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $female = Beneficiary::factory()->create([
            'first_name' => 'FILTERED',
            'last_name' => 'FEMALE',
            'sex' => 'FEMALE',
            'birthdate' => '2015-01-01',
            'source_id_no' => 'GOV-FILTER-1',
            'civil_status' => 'MARRIED',
        ]);

        $male = Beneficiary::factory()->create([
            'first_name' => 'FILTERED',
            'last_name' => 'MALE',
            'sex' => 'MALE',
            'birthdate' => '1990-01-01',
            'source_id_no' => null,
            'civil_status' => 'SINGLE',
        ]);

        $this->actingAs($admin)->get(route('reports.index', ['sex' => 'FEMALE']))
            ->assertOk()
            ->assertSee($female->beneficiary_code)
            ->assertDontSee($male->beneficiary_code);

        $this->actingAs($admin)->get(route('reports.index', ['age_range' => 'below18']))
            ->assertOk()
            ->assertSee($female->beneficiary_code)
            ->assertDontSee($male->beneficiary_code);

        $this->actingAs($admin)->get(route('reports.index', ['status' => 'missing']))
            ->assertOk()
            ->assertSee($male->beneficiary_code)
            ->assertDontSee($female->beneficiary_code);

        $this->actingAs($admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Age distribution')
            ->assertSee('Missing fields');
    }

    public function test_the_reports_status_and_page_size_controls_work(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $active = Beneficiary::factory()->create(['first_name' => 'STATUS', 'last_name' => 'ACTIVE']);
        $archived = Beneficiary::factory()->create(['first_name' => 'STATUS', 'last_name' => 'ARCHIVED']);
        $archived->archive('test archive');

        $this->actingAs($admin)->get(route('reports.index', ['status' => 'archived']))
            ->assertOk()
            ->assertSee($archived->beneficiary_code)
            ->assertDontSee($active->beneficiary_code);

        Beneficiary::factory()->count(60)->create();

        $page = $this->actingAs($admin)->get(route('reports.index', ['per_page' => 50, 'status' => 'all']))
            ->assertOk();

        $this->assertSame(50, $page->viewData('perPage'));
        $this->assertSame(50, $page->viewData('beneficiaries')->count());
        $this->assertSame(62, $page->viewData('beneficiaries')->total());

        $fallback = $this->actingAs($admin)->get(route('reports.index', ['per_page' => 999]))
            ->assertOk();

        $this->assertSame(25, $fallback->viewData('perPage'));

        $this->actingAs($admin)->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Archived')
            ->assertSee('Missing fields')
            ->assertSee('Rows per page');
    }
}
