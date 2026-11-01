<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Government-ID duplicates found during an import are flagged for
 * administrator review instead of aborting the whole batch.
 */
class ImportDuplicateReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function makeWorkbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('1,000');

        $sheet->setCellValue('A13', 'ID NUMBER');
        $sheet->setCellValue('B13', 'No.');
        $sheet->setCellValue('C13', 'Name of Beneficiary');
        $sheet->mergeCells('C13:F13');
        $sheet->setCellValue('G13', 'Birthdate');
        $sheet->setCellValue('H13', 'Project Location');
        $sheet->mergeCells('H13:K13');
        $sheet->setCellValue('L13', 'Type of ID');
        $sheet->mergeCells('L13:L14');
        $sheet->setCellValue('M13', 'ID No.');
        $sheet->mergeCells('M13:M14');
        $sheet->setCellValue('N13', 'Contact No.');
        $sheet->mergeCells('N13:N14');
        $sheet->setCellValue('O13', 'E-payment/Account No.');
        $sheet->mergeCells('O13:O14');
        $sheet->setCellValue('P13', 'Type of Beneficiary');
        $sheet->mergeCells('P13:P14');
        $sheet->setCellValue('Q13', 'Occupation');
        $sheet->mergeCells('Q13:Q14');
        $sheet->setCellValue('R13', 'Sex');
        $sheet->mergeCells('R13:R14');
        $sheet->setCellValue('S13', 'Civil Status');
        $sheet->mergeCells('S13:S14');
        $sheet->setCellValue('T13', 'Age');
        $sheet->mergeCells('T13:T14');
        $sheet->setCellValue('U13', 'Average monthly income');
        $sheet->mergeCells('U13:U14');
        $sheet->setCellValue('V13', 'Dependent');
        $sheet->mergeCells('V13:V14');
        $sheet->setCellValue('W13', 'Interested in wage employment or self-employment?');
        $sheet->mergeCells('W13:W14');
        $sheet->setCellValue('X13', 'Skills Training Needed');
        $sheet->mergeCells('X13:X14');

        $sheet->setCellValue('C14', 'First Name');
        $sheet->setCellValue('D14', 'Middle Name');
        $sheet->setCellValue('E14', 'Last Name');
        $sheet->setCellValue('F14', 'Extension Name');
        $sheet->setCellValue('G14', '(YYYY/MM/DD)');
        $sheet->setCellValue('H14', 'Brgy.');
        $sheet->setCellValue('I14', 'City/Municipality');
        $sheet->setCellValue('J14', 'Province');
        $sheet->setCellValue('K14', 'District');

        $rowNumber = 15;
        foreach ($rows as $row) {
            foreach ($row as $letter => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $cell = $sheet->getCell($letter.$rowNumber);
                    $date = \DateTime::createFromFormat('Y-m-d', $value->format('Y-m-d'));
                    $cell->setValueExplicit(Date::PHPToExcel($date), DataType::TYPE_NUMERIC);
                    $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                } else {
                    $sheet->setCellValue($letter.$rowNumber, $value);
                }
            }
            if (! isset($row['T'])) {
                $sheet->setCellValue('T'.$rowNumber, '=DATEDIF(G'.$rowNumber.',TODAY(),"Y")');
            }
            $rowNumber++;
        }

        $path = sys_get_temp_dir().'/tims-dup-review-'.uniqid().'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceRow(int $i, array $overrides = []): array
    {
        $defaults = [
            'A' => sprintf('DOLE XII -SCFO-BARMM-1507-102026-%06d', $i),
            'B' => $i,
            'C' => "FIRST{$i}",
            'D' => 'MIDDLE',
            'E' => "LAST{$i}",
            'F' => 'N/A',
            'G' => \DateTime::createFromFormat('Y-m-d', sprintf('1980-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1)),
            'H' => 'MATILAC',
            'I' => 'PAHAMUDDIN',
            'J' => 'SGA-BARMM',
            'K' => 'LONE',
            'L' => 'NATIONAL ID',
            'M' => sprintf('GOV-%04d', $i),
            'N' => '09171234567',
            'O' => 'MONEY REMITTANCE DIRECT PAYMENT',
            'P' => 'UNDEREMPLOYED/SELF-EMPLOYED WORKERS',
            'Q' => 'LABORER',
            'R' => 'MALE',
            'S' => 'MARRIED',
            'U' => 3000,
            'V' => 'DEPENDENT '.$i,
            'W' => 'Yes',
            'X' => 'AGRICULTURE CROPS PRODUCTION',
        ];

        return array_merge($defaults, $overrides);
    }

    private function upload(User $user, string $path, ?int $batchId = null): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('profile.xlsx', (string) file_get_contents($path));
        $payload = ['workbook' => $file];

        if ($batchId !== null) {
            $payload['beneficiary_batch_id'] = $batchId;
        }

        return $this->actingAs($user)->post('/imports', $payload);
    }

    private function uploadAndPreview(string $path, ?User $user = null, ?int $batchId = null): ImportBatch
    {
        $user ??= $this->staff();

        $this->upload($user, $path, $batchId)->assertSessionHasNoErrors();

        $batch = ImportBatch::query()->latest('id')->firstOrFail();

        $fields = [];
        foreach ($batch->mapping['fields'] as $field => $col) {
            $fields[$col] = $field;
        }

        $this->actingAs($user)->put('/imports/'.$batch->id.'/mapping', [
            'worksheet' => '1,000',
            'use_source_id_as_key' => 1,
            'fields' => $fields,
        ])->assertRedirect(route('imports.show', $batch));

        return $batch->refresh();
    }

    private function apply(ImportBatch $batch, User $admin): TestResponse
    {
        return $this->actingAs($admin)->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'insert', 'review' => 'skip'],
            'confirm' => 1,
        ]);
    }

    private function decide(ImportBatch $batch, ImportRow $row, string $action, User $admin): TestResponse
    {
        return $this->actingAs($admin)->from(route('imports.show', $batch))
            ->post(route('imports.duplicates.resolve', ['importBatch' => $batch, 'importRow' => $row->id]), [
                'action' => $action,
            ]);
    }

    private function conflictFixture(): array
    {
        $existing = Beneficiary::factory()->create([
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
            'source_id_no' => 'GOV-5001',
            'birthdate' => '1990-01-01',
        ]);

        $path = $this->makeWorkbook([
            $this->sourceRow(5001, [
                'C' => 'BRANDNEW',
                'E' => 'OTHERPERSON',
                'G' => \DateTime::createFromFormat('Y-m-d', '1985-06-06'),
                'M' => 'GOV-5001',
            ]),
            $this->sourceRow(5002),
        ]);

        return [$existing, $this->uploadAndPreview($path)];
    }

    public function test_government_id_duplicate_is_flagged_with_comparison_instead_of_failing(): void
    {
        [$existing, $batch] = $this->conflictFixture();

        $row = $batch->rows()->where('excel_row', 15)->firstOrFail();
        $this->assertSame('duplicate', $row->outcome);
        $this->assertSame('government_id', $row->duplicate_kind);
        $this->assertSame($existing->id, $row->matched_beneficiary_id);

        $response = $this->actingAs($this->admin())->get(route('imports.show', $batch));

        $response->assertOk()
            ->assertSee('Potential duplicate registrations')
            ->assertSee('Government ID match')
            ->assertSee('Existing registered beneficiary')
            ->assertSee('Imported row (from Excel)')
            ->assertSee('EXISTING')
            ->assertSee($existing->beneficiary_code)
            ->assertSee('GOV-5001')
            ->assertSee('Mark as already registered')
            ->assertSee('Update existing record')
            ->assertSee('Approve as new registration')
            ->assertSee('This government ID is unique on this server');

        $summary = $response->viewData('duplicateSummary');
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['potential_duplicates']);
        $this->assertSame(1, $summary['pending']);

        $duplicates = $response->viewData('duplicates');
        $this->assertTrue($duplicates[0]['hard_blocked']);
    }

    public function test_skip_decision_persists_and_row_is_skipped_on_apply(): void
    {
        [$existing, $batch] = $this->conflictFixture();
        $admin = $this->admin();
        $row = $batch->rows()->where('excel_row', 15)->firstOrFail();

        $this->decide($batch, $row, 'skip', $admin)
            ->assertRedirect(route('imports.show', $batch).'#duplicates')
            ->assertSessionHas('success');

        $row->refresh();
        $this->assertSame('skip', $row->review_action);
        $this->assertSame($admin->id, $row->reviewed_by);
        $this->assertNotNull($row->reviewed_at);

        // Persisted across a reload.
        $page = $this->actingAs($admin)->get(route('imports.show', $batch));
        $page->assertOk()->assertSee('Approved — already registered');
        $this->assertSame('approved_skip', $page->viewData('duplicates')[0]['status']);

        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch))->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame('applied', $batch->status);
        $this->assertSame(1, $batch->inserted);
        $this->assertSame(1, $batch->skipped);
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'GOV-5001')->count());
        $this->assertSame('EXISTING', $existing->refresh()->first_name);
    }

    public function test_update_existing_decision_updates_the_matched_record(): void
    {
        [$existing, $batch] = $this->conflictFixture();
        $admin = $this->admin();
        $row = $batch->rows()->where('excel_row', 15)->firstOrFail();

        $this->decide($batch, $row, 'update_existing', $admin)
            ->assertRedirect(route('imports.show', $batch).'#duplicates')
            ->assertSessionHas('success');

        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch));

        $existing->refresh();
        $this->assertSame('BRANDNEW', $existing->first_name);
        $this->assertSame('OTHERPERSON', $existing->last_name);
        $this->assertSame('1985-06-06', $existing->birthdate?->toDateString());
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'GOV-5001')->count());

        $batch->refresh();
        $this->assertSame(1, $batch->updated);
        $this->assertSame(1, (int) $batch->inserted);
    }

    public function test_approve_new_is_blocked_for_a_government_id_conflict(): void
    {
        [, $batch] = $this->conflictFixture();
        $admin = $this->admin();
        $row = $batch->rows()->where('excel_row', 15)->firstOrFail();

        $this->decide($batch, $row, 'approve_new', $admin)
            ->assertRedirect(route('imports.show', $batch).'#duplicates')
            ->assertSessionHas('error');

        $row->refresh();
        $this->assertNull($row->review_action);
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'GOV-5001')->count());
    }

    public function test_approve_new_is_allowed_for_a_name_duplicate_with_a_new_government_id(): void
    {
        // Register the person once, then import the same name/birthdate again
        // with a different government ID: this is a name-based duplicate that
        // can be approved as a separate registration.
        $firstPath = $this->makeWorkbook([
            $this->sourceRow(9001, [
                'C' => 'SAMENAME',
                'E' => 'PERSON',
                'G' => \DateTime::createFromFormat('Y-m-d', '1990-01-01'),
                'M' => 'OLD-GOV-1',
                'A' => 'DOLE XII -SCFO-BARMM-1507-102026-009001',
            ]),
        ]);

        $first = $this->uploadAndPreview($firstPath);
        $admin = $this->admin();
        $this->apply($first, $admin)->assertRedirect(route('imports.show', $first));

        $secondPath = $this->makeWorkbook([
            $this->sourceRow(9002, [
                'A' => 'DOLE XII -SCFO-BARMM-1507-102026-009002',
                'B' => 2,
                'C' => 'SAMENAME',
                'E' => 'PERSON',
                'G' => \DateTime::createFromFormat('Y-m-d', '1990-01-01'),
                'M' => 'NEW-GOV-9002',
            ]),
        ]);

        $batch = $this->uploadAndPreview($secondPath);
        $row = $batch->rows()->firstOrFail();

        $this->assertSame('duplicate', $row->outcome);
        $this->assertContains($row->duplicate_kind, ['name_birthdate', 'name_weak']);

        $page = $this->actingAs($admin)->get(route('imports.show', $batch));
        $this->assertFalse($page->viewData('duplicates')[0]['hard_blocked']);

        $this->decide($batch, $row, 'approve_new', $admin)
            ->assertRedirect(route('imports.show', $batch).'#duplicates')
            ->assertSessionHas('success');

        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch));

        $this->assertSame(2, Beneficiary::query()->where('last_name', 'PERSON')->count());
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'NEW-GOV-9002')->count());
    }

    public function test_unresolved_duplicates_are_skipped_even_when_group_decision_says_insert(): void
    {
        [, $batch] = $this->conflictFixture();
        $admin = $this->admin();

        // The apply() helper passes duplicate => insert for the group; the
        // unresolved government-ID row must still be skipped.
        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch));

        $row = $batch->rows()->where('excel_row', 15)->firstOrFail();
        $this->assertTrue($row->applied);
        $this->assertSame('skip', $row->operator_decision);
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'GOV-5001')->count());
    }

    public function test_race_conflict_at_insert_time_fails_only_that_row(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(7001, ['M' => 'RACE-GOV-1']),
            $this->sourceRow(7002),
        ]);

        $batch = $this->uploadAndPreview($path);
        $admin = $this->admin();

        // A conflicting registration appears after the dry-run but before apply.
        Beneficiary::factory()->create(['source_id_no' => 'RACE-GOV-1', 'first_name' => 'RACE', 'last_name' => 'WINNER']);

        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertSame('applied', $batch->status);
        $this->assertSame(1, $batch->inserted);

        $failedRow = $batch->rows()->where('excel_row', 15)->firstOrFail();
        $this->assertNotNull($failedRow->apply_error);
        $this->assertFalse($failedRow->applied);

        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'RACE-GOV-1')->count());

        $page = $this->actingAs($admin)->get(route('imports.show', $batch));
        $page->assertSee('could not be applied');
        $page->assertSee('Excel row 15');
        $this->assertSame(1, $page->viewData('duplicateSummary')['failed']);
    }

    public function test_in_file_government_id_duplicates_are_flagged_and_blocked(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(6001, ['M' => 'DUP-GOV-1']),
            $this->sourceRow(6002, [
                'M' => 'DUP-GOV-1',
                'C' => 'SECONDROW',
                'E' => 'SAMEPERSON',
                'G' => \DateTime::createFromFormat('Y-m-d', '1975-05-05'),
            ]),
        ]);

        $batch = $this->uploadAndPreview($path);
        $admin = $this->admin();

        $first = $batch->rows()->where('excel_row', 15)->firstOrFail();
        $second = $batch->rows()->where('excel_row', 16)->firstOrFail();

        $this->assertSame('new', $first->outcome);
        $this->assertSame('duplicate', $second->outcome);
        $this->assertSame('government_id_in_file', $second->duplicate_kind);

        $page = $this->actingAs($admin)->get(route('imports.show', $batch));
        $page->assertOk()
            ->assertSee('Potential duplicate registrations')
            ->assertSee('also appears in Excel row 15 of the same')
            ->assertSee('cannot be imported twice');

        $duplicates = $page->viewData('duplicates');
        $this->assertTrue($duplicates[0]['hard_blocked']);
        $this->assertEquals(15, $duplicates[0]['in_file_row']);

        // Approving the in-file duplicate as a new record is refused.
        $this->decide($batch, $second, 'approve_new', $admin)
            ->assertSessionHas('error');
        $this->assertNull($second->refresh()->review_action);

        // Skipping it lets the batch apply cleanly: first row imported, duplicate skipped.
        $this->decide($batch, $second, 'skip', $admin)
            ->assertRedirect(route('imports.show', $batch).'#duplicates')
            ->assertSessionHas('success');

        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertSame('applied', $batch->status);
        $this->assertSame(1, $batch->inserted);
        $this->assertSame(1, $batch->skipped);
        $this->assertSame(0, $batch->rows()->whereNotNull('apply_error')->count());
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'DUP-GOV-1')->count());
    }

    public function test_failed_row_links_point_at_the_row_and_the_conflicting_record(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(8001, ['M' => 'RACE-GOV-2']),
            $this->sourceRow(8002),
        ]);

        $batch = $this->uploadAndPreview($path);
        $admin = $this->admin();

        // Conflict appears after the dry-run (race).
        $existing = Beneficiary::factory()->create([
            'source_id_no' => 'RACE-GOV-2',
            'first_name' => 'RACE',
            'last_name' => 'WINNER',
        ]);

        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch));

        $failedRow = $batch->rows()->where('excel_row', 15)->firstOrFail();
        $page = $this->actingAs($admin)->get(route('imports.show', $batch));

        $page->assertOk()
            ->assertSee('could not be applied')
            ->assertSee('View Duplicate')
            ->assertSee('Edit')
            ->assertSee('Bypass leave blank')
            ->assertSee('id="import-row-'.$failedRow->id.'"', false)
            ->assertSee('RACE-GOV-2');
    }

    public function test_bypass_leave_blank_imports_row_and_flags_missing_field(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(8501, ['M' => 'RACE-GOV-3', 'C' => 'BYPASS', 'E' => 'CANDIDATE']),
        ]);

        $batch = $this->uploadAndPreview($path);
        $admin = $this->admin();

        // Conflict appears after the dry-run, so the apply leaves the row behind.
        $conflict = Beneficiary::factory()->create([
            'source_id_no' => 'RACE-GOV-3',
            'first_name' => 'EXISTING',
            'last_name' => 'OWNER',
        ]);

        $this->apply($batch, $admin)->assertRedirect(route('imports.show', $batch));

        $row = $batch->rows()->firstOrFail();
        $this->assertNotNull($row->apply_error);
        $this->assertFalse($row->applied);

        $this->actingAs($admin)
            ->from(route('imports.show', $batch))
            ->post(route('imports.rows.bypass', ['importBatch' => $batch, 'importRow' => $row->id]))
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHas('success');

        $row->refresh();
        $this->assertTrue($row->applied);
        $this->assertNull($row->apply_error);
        $this->assertSame('bypass_blank', $row->review_action);

        $created = Beneficiary::query()->where('last_name', 'CANDIDATE')->firstOrFail();
        $this->assertNull($created->source_id_no);
        $this->assertSame('source_id_no', $created->missing_fields);
        $this->assertContains('Government ID No.', $created->missingFieldLabels());

        // The conflicting record is untouched.
        $this->assertSame('RACE-GOV-3', $conflict->refresh()->source_id_no);

        // The Records -> Missing fields filter lists the bypassed record.
        $this->actingAs($admin)->get('/beneficiaries?status=missing')
            ->assertOk()
            ->assertSee('Missing fields')
            ->assertSee('CANDIDATE');

        // The profile page shows what is missing.
        $this->actingAs($admin)->get(route('beneficiaries.show', $created))
            ->assertOk()
            ->assertSee('Missing Government ID No.');
    }

    public function test_bypass_requires_an_admin_and_a_failed_row(): void
    {
        [, $batch] = $this->conflictFixture();
        $row = $batch->rows()->where('excel_row', 15)->firstOrFail();

        $this->actingAs($this->staff())
            ->post(route('imports.rows.bypass', ['importBatch' => $batch, 'importRow' => $row->id]))
            ->assertForbidden();
    }

    public function test_import_uses_the_batch_beneficiary_id_series(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create([
            'name' => 'Series Import Batch',
            'capacity' => 10,
            'beneficiary_code_series' => 'IMPORT-SERIES-00500',
        ]);

        $path = $this->makeWorkbook([
            $this->sourceRow(9101),
            $this->sourceRow(9102),
        ]);

        $import = $this->uploadAndPreview($path, null, $batch->id);

        $this->apply($import, $admin)->assertRedirect(route('imports.show', $import));

        $codes = Beneficiary::query()->orderBy('id')->pluck('beneficiary_code')->all();

        $this->assertSame('IMPORT-SERIES-00500', $codes[0]);
        $this->assertSame('IMPORT-SERIES-00501', $codes[1]);
        $this->assertSame('IMPORT-SERIES-00502', $batch->refresh()->beneficiary_code_series);
    }

    public function test_import_respects_the_target_batch_capacity(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Tiny Batch', 'capacity' => 1]);

        $path = $this->makeWorkbook([
            $this->sourceRow(9501),
            $this->sourceRow(9502),
        ]);

        $import = $this->uploadAndPreview($path, null, $batch->id);

        $this->apply($import, $admin)->assertRedirect(route('imports.show', $import));

        $import->refresh();
        $this->assertSame('applied', $import->status);
        $this->assertSame(1, $import->inserted);
        $this->assertSame(1, $batch->fresh()->memberCount());
        $this->assertSame(1, Beneficiary::query()->whereNotNull('beneficiary_batch_id')->count());

        $rejected = $import->rows()->whereNotNull('apply_error')->firstOrFail();
        $this->assertStringContainsString('full', (string) $rejected->apply_error);
    }

    public function test_only_administrators_can_review_duplicate_rows(): void
    {
        [, $batch] = $this->conflictFixture();
        $row = $batch->rows()->where('excel_row', 15)->firstOrFail();

        $this->decide($batch, $row, 'skip', $this->staff())->assertForbidden();
        $this->assertNull($row->refresh()->review_action);
    }
}
