<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryOptionValue;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\BeneficiaryOptions;
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
 * Unrecognized option values detected during an import can be resolved by
 * adding them to the standard options or replacing them with an existing one.
 */
class ImportInvalidOptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        BeneficiaryOptions::flushExtraCache();
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
     * @param  list<array<string, mixed>>  $rows  column letter => value
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

        $path = sys_get_temp_dir().'/tims-invalid-option-'.uniqid().'.xlsx';
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

    private function upload(User $user, string $path): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('profile.xlsx', (string) file_get_contents($path));

        return $this->actingAs($user)->post('/imports', ['workbook' => $file]);
    }

    private function uploadAndPreview(string $path, ?User $user = null): ImportBatch
    {
        $user ??= $this->staff();

        $response = $this->upload($user, $path);
        $response->assertSessionHasNoErrors();

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

    public function test_unrecognized_options_are_listed_with_resolution_actions(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1, ['L' => "VOTER'S CERTIFICATE"])]);
        $batch = $this->uploadAndPreview($path);

        $response = $this->actingAs($this->admin())->get(route('imports.show', $batch));

        $response->assertOk()
            ->assertSee('Unrecognized option values')
            ->assertSee('Type of ID')
            ->assertSee('VOTER&#039;S CERTIFICATE', false)
            ->assertSee('1 affected')
            ->assertSee('as a new option')
            ->assertSee('Replace with')
            ->assertSee(route('imports.invalid-options.resolve', $batch), false);

        $options = $response->viewData('invalidOptions');
        $this->assertCount(1, $options);
        $this->assertSame('id_type', $options[0]['field']);
        $this->assertSame("VOTER'S CERTIFICATE", $options[0]['value']);
        $this->assertSame(1, $options[0]['count']);
    }

    public function test_add_as_new_option_makes_the_value_standard(): void
    {
        $admin = $this->admin();
        $path = $this->makeWorkbook([$this->sourceRow(1, ['L' => "VOTER'S CERTIFICATE"])]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($admin)
            ->post(route('imports.invalid-options.resolve', $batch), [
                'action' => 'add',
                'field' => 'id_type',
                'value' => "VOTER'S CERTIFICATE",
            ])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('beneficiary_option_values', [
            'field' => 'id_type',
            'value' => "VOTER'S CERTIFICATE",
        ]);

        BeneficiaryOptions::flushExtraCache();
        $this->assertArrayHasKey("VOTER'S CERTIFICATE", BeneficiaryOptions::idTypes());
        $this->assertFalse(BeneficiaryOptions::isLegacy('id_type', "VOTER'S CERTIFICATE"));

        // Resolution removes the warning from the review.
        $response = $this->actingAs($admin)->get(route('imports.show', $batch));
        $response->assertOk();
        $this->assertSame([], $response->viewData('invalidOptions'));
        $response->assertDontSee('Unrecognized option values');

        // Future imports of the same value no longer raise the warning.
        $second = $this->uploadAndPreview($this->makeWorkbook([
            $this->sourceRow(2, ['L' => "VOTER'S CERTIFICATE"]),
        ]));
        $this->assertSame([], $this->actingAs($admin)->get(route('imports.show', $second))->viewData('invalidOptions'));

        // The option is now valid for manual beneficiary creation.
        $location = $this->seededLocation();
        $targetBatch = $this->seededBatch(['name' => 'Option Resolution Batch']);
        $payload = $this->beneficiaryPayload($location, [
            'first_name' => 'MANUAL',
            'middle_name' => 'M',
            'last_name' => 'ENTRY',
            'birthdate' => '1990-05-05',
            'id_type' => "VOTER'S CERTIFICATE",
            'source_id_no' => 'NEW-GOV-1',
            'source_id_number' => 'DOLE XII -SCFO-BARMM-1507-102026-000999',
            'beneficiary_type' => 'UNDEREMPLOYED/SELF-EMPLOYED WORKERS',
            'occupation' => 'LABORER',
            'mode_of_payment' => 'BANK',
        ]);

        $this->actingAs($admin)->post('/batches/'.$targetBatch->id.'/beneficiaries', $payload)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('beneficiaries', ['id_type' => "VOTER'S CERTIFICATE"]);
    }

    public function test_adding_a_duplicate_option_is_prevented(): void
    {
        $admin = $this->admin();
        $path = $this->makeWorkbook([$this->sourceRow(1, ['L' => "VOTER'S CERTIFICATE"])]);
        $batch = $this->uploadAndPreview($path);

        foreach (['add', 'add'] as $attempt) {
            $this->actingAs($admin)->post(route('imports.invalid-options.resolve', $batch), [
                'action' => 'add',
                'field' => 'id_type',
                'value' => "VOTER'S CERTIFICATE",
            ])->assertRedirect(route('imports.show', $batch));
        }

        $this->assertSame(1, BeneficiaryOptionValue::query()->count());
    }

    public function test_replace_with_existing_option_updates_rows_and_applied_beneficiaries(): void
    {
        $admin = $this->admin();
        $path = $this->makeWorkbook([$this->sourceRow(1, ['L' => "VOTER'S CERTIFICATE"])]);
        $batch = $this->uploadAndPreview($path);

        // Apply first so the beneficiary records exist with the raw value.
        $this->actingAs($admin)->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('beneficiaries', ['id_type' => "VOTER'S CERTIFICATE"]);

        $this->actingAs($admin)
            ->post(route('imports.invalid-options.resolve', $batch), [
                'action' => 'replace',
                'field' => 'id_type',
                'value' => "VOTER'S CERTIFICATE",
                'replacement' => 'NATIONAL ID',
            ])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('beneficiaries', ['id_type' => 'NATIONAL ID']);
        $this->assertDatabaseMissing('beneficiaries', ['id_type' => "VOTER'S CERTIFICATE"]);

        $row = $batch->rows()->firstOrFail();
        $this->assertSame('NATIONAL ID', $row->payload['id_type']);
        $this->assertSame([], $this->actingAs($admin)->get(route('imports.show', $batch))->viewData('invalidOptions'));
    }

    public function test_replace_before_apply_inserts_the_replacement_value(): void
    {
        $admin = $this->admin();
        $path = $this->makeWorkbook([$this->sourceRow(1, ['L' => "VOTER'S CERTIFICATE"])]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($admin)->post(route('imports.invalid-options.resolve', $batch), [
            'action' => 'replace',
            'field' => 'id_type',
            'value' => "VOTER'S CERTIFICATE",
            'replacement' => 'NATIONAL ID',
        ])->assertRedirect(route('imports.show', $batch));

        $this->actingAs($admin)->post('/imports/'.$batch->id.'/apply', [
            'decisions' => ['new' => 'insert', 'update' => 'update', 'duplicate' => 'skip', 'review' => 'skip'],
            'confirm' => 1,
        ])->assertRedirect(route('imports.show', $batch));

        $this->assertDatabaseHas('beneficiaries', ['id_type' => 'NATIONAL ID']);
    }

    public function test_invalid_replacement_is_rejected(): void
    {
        $admin = $this->admin();
        $path = $this->makeWorkbook([$this->sourceRow(1, ['L' => "VOTER'S CERTIFICATE"])]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($admin)
            ->from(route('imports.show', $batch))
            ->post(route('imports.invalid-options.resolve', $batch), [
                'action' => 'replace',
                'field' => 'id_type',
                'value' => "VOTER'S CERTIFICATE",
                'replacement' => 'NOT A REAL OPTION',
            ])
            ->assertRedirect(route('imports.show', $batch))
            ->assertSessionHas('error');

        $row = $batch->rows()->firstOrFail();
        $this->assertSame("VOTER'S CERTIFICATE", $row->payload['id_type']);
        $this->assertNotEmpty($this->actingAs($admin)->get(route('imports.show', $batch))->viewData('invalidOptions'));
    }

    public function test_only_administrators_can_resolve_options(): void
    {
        $path = $this->makeWorkbook([$this->sourceRow(1, ['L' => "VOTER'S CERTIFICATE"])]);
        $batch = $this->uploadAndPreview($path);

        $this->actingAs($this->staff())
            ->post(route('imports.invalid-options.resolve', $batch), [
                'action' => 'add',
                'field' => 'id_type',
                'value' => "VOTER'S CERTIFICATE",
            ])
            ->assertForbidden();

        $this->assertSame(0, BeneficiaryOptionValue::query()->count());
    }
}
