<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportBatch;
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

class ImportWorkflowTest extends TestCase
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
     * Build an .xlsx with the real workbook's two-row header (rows 13–14,
     * data from row 15).
     *
     * @param  list<array<string, mixed>>  $rows  column letter => value
     */
    private function makeWorkbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('1,000');

        // row 13: group headers
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
        $sheet->setCellValue('W13', "Interested in wage employment or self-employment?\n(Yes/No, if Yes, please specify)");
        $sheet->mergeCells('W13:W14');
        $sheet->setCellValue('X13', 'Skills Training Needed');
        $sheet->mergeCells('X13:X14');

        // row 14: sub-headers
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
                    // store like the real workbook: numeric serial + date number format
                    $cell = $sheet->getCell($letter.$rowNumber);
                    $date = \DateTime::createFromFormat('Y-m-d', $value->format('Y-m-d'));
                    $cell->setValueExplicit(Date::PHPToExcel($date), DataType::TYPE_NUMERIC);
                    $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                } elseif (is_string($value) && str_starts_with($value, '=')) {
                    // text that merely looks like a formula (injection payload)
                    $sheet->setCellValueExplicit($letter.$rowNumber, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($letter.$rowNumber, $value);
                }
            }
            // age formula like the real workbook
            if (! isset($row['T'])) {
                $sheet->setCellValue('T'.$rowNumber, '=DATEDIF(G'.$rowNumber.',TODAY(),"Y")');
            }
            $rowNumber++;
        }

        $path = sys_get_temp_dir().'/tims-import-'.uniqid().'.xlsx';
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
            'O' => 'Money Remittance',
            'P' => 'SELF-EMPLOYED/UNDEREMPLOYED',
            'Q' => 'FARMER',
            'R' => 'MALE',
            'S' => 'MARRIED',
            'U' => 3000,
            'V' => 'DEPENDENT '.$i,
            'W' => 'Yes',
            'X' => 'AGRICULTURE CROPS PRODUCTION',
        ];

        return array_merge($defaults, $overrides);
    }

    private function upload(User $user, string $path, ?int $beneficiaryBatchId = null, ?string $profilingDate = null): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('profile.xlsx', (string) file_get_contents($path));
        $payload = ['workbook' => $file];

        if ($beneficiaryBatchId !== null) {
            $payload['beneficiary_batch_id'] = $beneficiaryBatchId;
        }

        if ($profilingDate !== null) {
            $payload['profiling_date'] = $profilingDate;
        }

        return $this->actingAs($user)->post('/imports', $payload);
    }

    private function uploadAndPreview(string $path, ?User $user = null, ?int $beneficiaryBatchId = null, ?string $profilingDate = null): ImportBatch
    {
        $user ??= $this->staff();

        $response = $this->upload($user, $path, $beneficiaryBatchId, $profilingDate);
        $response->assertSessionHasNoErrors();
        $response->assertRedirectContains('/imports/');
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

    public function test_guest_cannot_upload(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);

        $file = UploadedFile::fake()->createWithContent('profile.xlsx', (string) file_get_contents($path));
        $this->post('/imports', ['workbook' => $file])->assertRedirect('/login');
    }

    public function test_dry_run_then_apply_inserts_new_rows(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(1),
            $this->sourceRow(2),
        ]);

        $batch = $this->uploadAndPreview($path);

        $this->assertSame('previewed', $batch->status);
        $this->assertSame(2, $batch->rows_read);
        $this->assertSame(0, Beneficiary::query()->count(), 'dry-run must not write beneficiaries');

        $admin = $this->admin();
        $this->actingAs($admin)->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch));

        $batch->refresh();
        $this->assertSame('applied', $batch->status);
        $this->assertSame(2, $batch->inserted);
        $this->assertSame(2, Beneficiary::query()->count());

        $first = Beneficiary::query()->where('last_name', 'LAST1')->firstOrFail();
        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00001', $first->beneficiary_code);
        $this->assertSame('DOLE XII -SCFO-BARMM-1507-102026-000001', $first->source_id_number);
        $this->assertNull($first->extension_name, 'N/A extension must become null');
        $this->assertSame('MATILAC', $first->barangay);
        $this->assertSame(1, $first->import_batch_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import.applied', 'entity_id' => $batch->id]);

        // uploaded file is deleted after successful apply (docs §11)
        Storage::disk('local')->assertMissing($batch->stored_path);
    }

    public function test_a_row_whose_government_id_already_exists_is_flagged_for_review_instead_of_failing_the_batch(): void
    {
        $admin = $this->admin();

        // Someone already registered this government ID on this server; the
        // workbook row reuses it for a different person.
        $existing = Beneficiary::factory()->create([
            'source_id_no' => 'GOV-5001',
            'first_name' => 'EXISTING',
            'last_name' => 'PERSON',
        ]);

        $path = $this->makeWorkbook([
            $this->sourceRow(5001, [
                'C' => 'BRANDNEW',
                'E' => 'OTHERPERSON',
                'M' => 'GOV-5001',
            ]),
            $this->sourceRow(5002),
        ]);

        $batch = $this->uploadAndPreview($path, $admin);

        // Dry-run flags the collision as a duplicate; the valid row stays new.
        $collision = $batch->rows()->where('excel_row', 15)->firstOrFail();
        $this->assertSame('duplicate', $collision->outcome);
        $this->assertSame('government_id', $collision->duplicate_kind);
        $this->assertSame($existing->id, $collision->matched_beneficiary_id);

        $summary = $batch->rows()->selectRaw('outcome, COUNT(*) as total')->groupBy('outcome')->pluck('total', 'outcome');
        $this->assertSame(1, (int) ($summary['duplicate'] ?? 0));
        $this->assertSame(1, (int) ($summary['new'] ?? 0));

        // The unresolved duplicate is skipped; the valid row is imported and
        // the batch succeeds instead of aborting.
        $this->actingAs($admin)->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'insert', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch))
            ->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame('applied', $batch->status);
        $this->assertSame(1, $batch->inserted);
        $this->assertSame(1, $batch->skipped);
        $this->assertSame(1, Beneficiary::query()->where('source_id_no', 'GOV-5001')->count());
        $this->assertSame('EXISTING', Beneficiary::query()->where('source_id_no', 'GOV-5001')->value('first_name'));
    }

    public function test_repeat_import_of_same_file_is_all_unchanged(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);

        $batch = $this->uploadAndPreview($path);
        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert'],
            'confirm' => 1,
        ]);

        $this->assertSame(1, Beneficiary::query()->count());

        $batch2 = $this->uploadAndPreview($path);
        $this->assertSame(1, $batch2->rows_read);
        $this->assertSame(1, $batch2->rows()->where('outcome', 'unchanged')->count());

        $this->actingAs($this->admin())->post('/imports/'.$batch2->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update'],
            'confirm' => 1,
        ]);

        $batch2->refresh();
        $this->assertSame(0, $batch2->inserted);
        $this->assertSame(0, $batch2->updated);
        $this->assertSame(1, Beneficiary::query()->count());
    }

    public function test_reimport_moves_matched_rows_into_the_selected_release_batch(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);
        $firstBatch = BeneficiaryBatch::factory()->create();
        $targetBatch = BeneficiaryBatch::factory()->create();

        $batch = $this->uploadAndPreview($path, null, $firstBatch->id);
        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert'],
            'confirm' => 1,
        ]);

        $beneficiary = Beneficiary::query()->firstOrFail();
        $this->assertSame($firstBatch->id, $beneficiary->beneficiary_batch_id);

        $batch2 = $this->uploadAndPreview($path, null, $targetBatch->id);
        $this->assertSame(1, $batch2->rows()->where('outcome', 'update')->count());

        $this->actingAs($this->admin())->post('/imports/'.$batch2->id.'/apply', [
            'decisions' => ['update' => 'update'],
            'confirm' => 1,
        ]);

        $batch2->refresh();
        $this->assertSame(1, $batch2->updated);
        $this->assertSame($targetBatch->id, $beneficiary->refresh()->beneficiary_batch_id);
    }

    public function test_modified_copy_produces_updates_and_new_rows(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1), $this->sourceRow(2)]);
        $batch = $this->uploadAndPreview($path);
        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert'],
            'confirm' => 1,
        ]);

        $edited = $this->makeWorkbook([
            $this->sourceRow(1, ['Q' => 'FISHERMAN']),   // changed occupation -> update
            $this->sourceRow(3),                          // brand new -> new
            // sourceRow(2) removed entirely
        ]);

        $batch2 = $this->uploadAndPreview($edited);

        $this->assertSame(2, $batch2->rows_read);
        $this->assertSame(1, $batch2->rows()->where('outcome', 'update')->count());
        $this->assertSame(1, $batch2->rows()->where('outcome', 'new')->count());
        $this->assertSame(0, $batch2->rows()->where('outcome', 'unchanged')->count());

        $this->actingAs($this->admin())->post('/imports/'.$batch2->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update'],
            'confirm' => 1,
        ]);

        $this->assertSame(3, Beneficiary::query()->count());
        $updated = Beneficiary::query()->where('last_name', 'LAST1')->firstOrFail();
        $this->assertSame('FISHERMAN', $updated->occupation);
        $this->assertSame('DOLE XII-SCFO-BARMM-'.now()->format('mY').'-00001', $updated->beneficiary_code, 'code must not change on update');
        $this->assertTrue(
            Beneficiary::query()->where('last_name', 'LAST2')->exists(),
            'rows missing from a re-import must not be deleted',
        );
    }

    public function test_existing_name_and_birthdate_is_a_duplicate_candidate_not_an_update(): void
    {
        Beneficiary::factory()
            ->named('ADA', 'ADAM', 'TESTER')
            ->withBirthdate('1990-05-05')
            ->create();

        $path = $this->makeWorkbook([[
            'A' => 'DOLE-DUP-1',
            'B' => 1,
            'C' => 'ADA', 'D' => 'ADAM', 'E' => 'TESTER', 'F' => 'N/A',
            'G' => new \DateTime('1990-05-05'),
            'H' => 'MATILAC', 'I' => 'PAHAMUDDIN', 'J' => 'SGA-BARMM', 'K' => 'LONE',
            'L' => 'NATIONAL ID', 'M' => 'GOV-DUP', 'N' => '09171234567', 'O' => 'Money Remittance',
            'P' => 'SELF-EMPLOYED/UNDEREMPLOYED', 'Q' => 'FARMER', 'R' => 'MALE', 'S' => 'MARRIED',
            'U' => 3000, 'V' => 'DEP', 'W' => 'Yes', 'X' => 'AGRICULTURE CROPS PRODUCTION',
        ]]);

        $batch = $this->uploadAndPreview($path);

        $row = $batch->rows()->firstOrFail();
        $this->assertSame('duplicate', $row->outcome);
        $this->assertNotEmpty($row->candidate_ids);

        // default decision: skip — no auto-merge, no new person
        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['duplicate' => 'skip'],
            'confirm' => 1,
        ]);
        $this->assertSame(1, Beneficiary::query()->count());
        $this->assertSame('none', Beneficiary::query()->firstOrFail()->duplicate_status);

        // explicit decision: import as separate person (flagged as candidate)
        $batch2 = $this->uploadAndPreview($path);
        $this->actingAs($this->admin())->post('/imports/'.$batch2->id.'/apply', [
            'decisions' => ['duplicate' => 'insert'],
            'confirm' => 1,
        ]);

        $this->assertSame(2, Beneficiary::query()->count());
        $this->assertSame(1, Beneficiary::query()->where('duplicate_status', 'candidate')->count());
    }

    public function test_invalid_and_review_rows_are_skipped_unless_acknowledged(): void
    {
        $path = $this->makeWorkbook([
            ['A' => 'DOLE-INV-1', 'B' => 1, 'C' => '', 'E' => 'NONAME'], // missing first name
            $this->sourceRow(2, ['A' => 'DOLE-BAD-DATE', 'G' => '2008-13-45']),
            $this->sourceRow(3, ['A' => 'DOLE-NO-DATE', 'G' => null]),
        ]);

        $batch = $this->uploadAndPreview($path);

        $this->assertSame(1, $batch->rows()->where('outcome', 'invalid')->count());
        $this->assertSame(2, $batch->rows()->where('outcome', 'review')->count());

        $reviewRow = $batch->rows()->where('excel_row', 16)->firstOrFail();
        $this->assertSame('2008-13-45', $reviewRow->payload['birthdate_raw']);
        $this->assertNull($reviewRow->payload['birthdate']);

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['review' => 'skip', 'invalid' => 'skip'],
            'confirm' => 1,
        ]);
        $this->assertSame(0, Beneficiary::query()->count());

        $batch2 = $this->uploadAndPreview($path);
        $this->actingAs($this->admin())->post('/imports/'.$batch2->id.'/apply', [
            'decisions' => ['review' => 'insert'],
            'confirm' => 1,
        ]);

        $this->assertSame(2, Beneficiary::query()->count());
        // both review rows imported with null birthdate (bad date and missing date)
        $this->assertSame(2, Beneficiary::query()->whereNull('birthdate')->count());
        $this->assertTrue(Beneficiary::query()->where('source_id_number', 'DOLE-NO-DATE')->exists());
        $this->assertFalse(Beneficiary::query()->where('source_id_number', 'DOLE-INV-1')->exists());
    }

    public function test_trailing_apostrophe_in_birthdate_is_accepted(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(1, ['G' => "1991/7/20'"]),
        ]);

        $batch = $this->uploadAndPreview($path);
        $row = $batch->rows()->firstOrFail();

        $this->assertSame('new', $row->outcome);
        $this->assertSame('1991-07-20', $row->payload['birthdate']);
        $this->assertSame("1991/7/20'", $row->payload['birthdate_raw']);
    }

    public function test_staff_can_preview_but_cannot_apply(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($this->staff())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert'],
            'confirm' => 1,
        ])->assertForbidden();

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_whitespace_is_trimmed_on_import(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(1, ['C' => '  PADDED  ', 'Q' => ' DRIVER', 'S' => 'MARRIED ']),
        ]);

        $batch = $this->uploadAndPreview($path);
        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert'],
            'confirm' => 1,
        ]);

        $beneficiary = Beneficiary::query()->firstOrFail();
        $this->assertSame('PADDED', $beneficiary->first_name);
        $this->assertSame('DRIVER', $beneficiary->occupation);
        $this->assertSame('MARRIED', $beneficiary->civil_status);
    }

    public function test_report_csv_is_bom_prefixed_and_formula_safe(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(1, ['A' => '=cmd|calc']),
            $this->sourceRow(2, ['A' => '=SUM(A1:A3)']),
        ]);

        $batch = $this->uploadAndPreview($path);

        $response = $this->actingAs($this->staff())->get('/imports/'.$batch->id.'/report');
        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('excel_row,outcome,severity,field,message', $content);
        $this->assertStringContainsString("'=SUM(A1:A3)", $content);
        $this->assertStringContainsString("'=CMD|CALC", $content);
    }

    public function test_cancelled_batch_deletes_file_and_blocks_apply(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);
        $batch = $this->uploadAndPreview($path);

        Storage::disk('local')->assertExists($batch->stored_path);

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/cancel')
            ->assertRedirect(route('imports.index'));

        $this->assertSame('cancelled', $batch->refresh()->status);
        Storage::disk('local')->assertMissing($batch->stored_path);

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch));

        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_preview_page_shows_structured_import_workflow(): void
    {
        $path = $this->makeWorkbook([
            $this->sourceRow(1),
            $this->sourceRow(2),
            $this->sourceRow(3, ['G' => null]),          // missing birthdate → warning
            $this->sourceRow(4, ['C' => '', 'E' => '']), // missing names → invalid row
        ]);

        $batch = $this->uploadAndPreview($path);

        $response = $this->actingAs($this->admin())->get(route('imports.show', $batch));

        $response->assertOk()
            // A. header identity
            ->assertSee('Import batch #'.$batch->id)
            ->assertSee('Dry-run complete — awaiting confirmation')
            ->assertSee('Worksheet')
            ->assertSee('Header row')
            ->assertSee('Uploaded by')
            ->assertSee('Target batch')
            // B. dry-run banner
            ->assertSee('Dry-run completed successfully')
            ->assertSee('No changes have been written to the beneficiaries table')
            ->assertSee('ready for administrator confirmation')
            // C. overview hierarchy
            ->assertSee('Rows read')
            ->assertSee('New records')
            ->assertSee('Needs review')
            ->assertSee('Duplicate candidates')
            ->assertSee('Invalid rows')
            // D. decisions + confirmation
            ->assertSee('Decisions')
            ->assertSee('I reviewed the import summary and the decisions above')
            ->assertSee('Confirm the summary above to unlock applying')
            ->assertSee('name="decisions[new]"', false)
            ->assertSee('name="decisions[update]"', false)
            ->assertSee('name="decisions[duplicate]"', false)
            ->assertSee('name="decisions[review]"', false)
            ->assertSee('name="confirm"', false)
            // E. preview table
            ->assertSee('Excel row')
            ->assertSee('Beneficiary name')
            ->assertSee('Age status')
            ->assertSee('Import outcome')
            ->assertSee('Validation / warnings')
            ->assertSee('Matched / candidates')
            // Validation summary for recurring file notes, in plain language
            ->assertSee('Recurring validation notes in this file')
            ->assertSee('It is kept exactly as written in the file; review it when editing the record.')
            ->assertSee('This row has no birthdate. It needs review before the record is used.');

        // Counts rendered on the page are exactly the backend aggregation.
        $backendSummary = $batch->rows()
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $this->assertSame($backendSummary->all(), $response->viewData('summary')->all());

        // Dry-run must never write beneficiaries.
        $this->assertSame(0, Beneficiary::query()->count(), 'preview must not write beneficiaries');
    }

    public function test_apply_requires_the_confirmation_checkbox(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($this->admin())
            ->from(route('imports.show', $batch))
            ->post('/imports/'.$batch->id.'/apply', [
                'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            ])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHasErrors('confirm');

        $this->assertSame('previewed', $batch->refresh()->status);
        $this->assertSame(0, Beneficiary::query()->count());
    }

    public function test_applied_batch_page_reports_applied_state(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch));

        $this->actingAs($this->admin())->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('Applied.')
            ->assertSee('1 inserted')
            ->assertSee('Browse beneficiaries');
    }

    public function test_cancelled_batch_page_reports_cancelled_state(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/cancel')
            ->assertRedirect(route('imports.index'));

        $this->actingAs($this->admin())->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('This batch was cancelled; the uploaded file was deleted.')
            ->assertSee('Cancelled');
    }

    public function test_reimport_preview_shows_unchanged_rows(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1), $this->sourceRow(2)]);

        $first = $this->uploadAndPreview($path);
        $this->actingAs($this->admin())->post('/imports/'.$first->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $first));

        $second = $this->uploadAndPreview($path);
        $response = $this->actingAs($this->admin())->get(route('imports.show', $second));

        $response->assertOk()->assertSee('Unchanged');

        $summary = $response->viewData('summary');
        $this->assertSame(2, (int) ($summary['unchanged'] ?? 0));
        $this->assertSame(0, (int) ($summary['new'] ?? 0));
    }

    public function test_import_page_shows_profiling_date_picker_defaulting_to_today(): void
    {
        $this->actingAs($this->staff())->get('/imports/create')
            ->assertOk()
            ->assertSee('Beneficiary Profiling Date')
            ->assertSee('This date will be used for monthly and yearly dashboard reporting.')
            ->assertSee('name="profiling_date"', false)
            ->assertSee('value="'.now()->toDateString().'"', false);
    }

    public function test_invalid_profiling_date_is_rejected(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);

        $response = $this->upload($this->staff(), $path, null, 'not-a-date');

        $response->assertSessionHasErrors('profiling_date');
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_selected_profiling_date_is_stored_and_shown_through_preview(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);

        $this->upload($this->staff(), $path, null, '2026-10-26')->assertSessionHasNoErrors();
        $batch = ImportBatch::query()->latest('id')->firstOrFail();

        $this->assertSame('2026-10-26', $batch->profiling_date?->toDateString());

        // Preserved when returning to the mapping step.
        $this->actingAs($this->staff())->get(route('imports.edit', $batch))
            ->assertOk()
            ->assertSee('Beneficiary profiling date:')
            ->assertSee('October 26, 2026');

        $batch = $this->uploadAndPreview($path, null, null, '2025-03-15');

        $this->actingAs($this->admin())->get(route('imports.show', $batch))
            ->assertOk()
            ->assertSee('Beneficiary profiling date')
            ->assertSee('March 15, 2025')
            ->assertSee('recorded under')
            ->assertSee('March 2025')
            ->assertSee('Profiling date:');
    }

    public function test_apply_records_the_profiling_date_and_feeds_the_dashboard_trend(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1), $this->sourceRow(2)]);

        $batch = $this->uploadAndPreview($path, null, null, '2025-03-15');

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch));

        $this->assertSame(2, Beneficiary::query()->whereDate('profiled_on', '2025-03-15')->count());

        $trend = $this->actingAs($this->admin())->get('/dashboard')->viewData('registrationTrend');

        $this->assertSame(2, $trend['months'][2025][3]);
        $this->assertSame(2, $trend['annual'][2025]);
        $this->assertSame(0, $trend['months'][now()->year][now()->month]);
    }

    public function test_update_does_not_overwrite_an_existing_profiling_date(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1, ['U' => 2000])]);
        $first = $this->uploadAndPreview($path, null, null, '2025-03-15');

        $this->actingAs($this->admin())->post('/imports/'.$first->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $first));

        $beneficiary = Beneficiary::query()->firstOrFail();
        $this->assertSame('2025-03-15', $beneficiary->profiled_on?->toDateString());

        // A later import updates the record (income changed) with a different
        // profiling date; the original profiling date must be preserved.
        $changed = $this->makeWorkbook([$this->sourceRow(1, ['U' => 4000])]);
        $second = $this->uploadAndPreview($changed, null, null, '2024-06-01');

        $this->actingAs($this->admin())->post('/imports/'.$second->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $second));

        $beneficiary->refresh();
        $this->assertSame('2025-03-15', $beneficiary->profiled_on?->toDateString());
        $this->assertSame('4000.00', (string) $beneficiary->avg_monthly_income);
    }

    public function test_update_fills_a_missing_profiling_date(): void
    {
        $factoryBeneficiary = Beneficiary::factory()->create([
            'first_name' => 'FIRST1',
            'last_name' => 'LAST1',
            'source_id_number' => 'DOLE XII -SCFO-BARMM-1507-102026-000001',
            'source_id_no' => 'GOV-0001',
            'profiled_on' => null,
        ]);

        $path = $this->makeWorkbook([$this->sourceRow(1, ['U' => 9999])]);
        $batch = $this->uploadAndPreview($path, null, null, '2024-09-09');

        $summary = $batch->rows()->pluck('outcome', 'excel_row');
        $this->assertSame('update', $summary->first());

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch));

        $factoryBeneficiary->refresh();
        $this->assertSame('2024-09-09', $factoryBeneficiary->profiled_on?->toDateString());
    }

    public function test_cancelled_import_does_not_touch_beneficiary_profiling_dates(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1)]);
        $batch = $this->uploadAndPreview($path, null, null, '2023-01-10');

        $this->actingAs($this->admin())->post('/imports/'.$batch->id.'/cancel')
            ->assertRedirect(route('imports.index'));

        $this->assertSame('cancelled', $batch->refresh()->status);
        $this->assertSame(0, Beneficiary::query()->count());
    }
}
