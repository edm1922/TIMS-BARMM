<?php

namespace App\Console\Commands;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportRow;
use App\Models\Location;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RepairPahamuddinCollision extends Command
{
    protected $signature = 'imports:repair-pahamuddin-collision
        {--apply : Apply the repair (default is dry-run)}
        {--source-import=1 : Import batch containing the original PAHAMUDDIN rows}
        {--source-batch=2 : Release batch to restore}
        {--target-batch=5 : Release batch that received the overwritten rows}
        {--expected=100 : Expected number of overwritten records}';

    protected $description = 'Restore PAHAMUDDIN beneficiaries overwritten by reused DOLE ID numbers';

    public function handle(): int
    {
        $sourceImportId = (int) $this->option('source-import');
        $sourceBatchId = (int) $this->option('source-batch');
        $targetBatchId = (int) $this->option('target-batch');
        $expected = (int) $this->option('expected');
        $apply = (bool) $this->option('apply');

        $sourceBatch = BeneficiaryBatch::query()->find($sourceBatchId);
        $targetBatch = BeneficiaryBatch::query()->find($targetBatchId);

        if ($sourceBatch === null || $targetBatch === null) {
            $this->error('Source or target release batch was not found.');

            return Command::FAILURE;
        }

        $moved = Beneficiary::query()
            ->where('import_batch_id', $sourceImportId)
            ->where('beneficiary_batch_id', $targetBatchId)
            ->orderBy('id')
            ->get();

        if ($moved->isEmpty()) {
            $this->error('No overwritten records were found. Nothing to repair.');

            return Command::FAILURE;
        }

        if ($expected > 0 && $moved->count() !== $expected) {
            $this->error("Expected {$expected} overwritten records, found {$moved->count()}. Aborting.");

            return Command::FAILURE;
        }

        $originalRows = ImportRow::query()
            ->where('import_batch_id', $sourceImportId)
            ->get()
            ->keyBy(fn (ImportRow $row) => $row->payload['source_id_number'] ?? '');

        $datuRows = ImportRow::query()
            ->whereIn('import_batch_id', [2, 4])
            ->whereIn('matched_beneficiary_id', $moved->pluck('id')->all())
            ->orderBy('import_batch_id')
            ->orderBy('excel_row')
            ->get();

        $repairRows = [];
        $datuExportRows = [];
        $errors = [];

        foreach ($moved as $beneficiary) {
            $original = $originalRows->get($beneficiary->source_id_number);

            if (! $original instanceof ImportRow) {
                $errors[] = "Beneficiary {$beneficiary->id}: original import row not found for source ID {$beneficiary->source_id_number}.";

                continue;
            }

            $repairRows[] = [
                'beneficiary_id' => $beneficiary->id,
                'beneficiary_code' => $beneficiary->beneficiary_code,
                'source_id_number' => $beneficiary->source_id_number,
                'original_name' => $this->name($original->payload),
                'original_birthdate' => $original->payload['birthdate'] ?? '',
                'original_address' => $original->payload['address'] ?? '',
                'current_name' => $this->name($beneficiary->only([
                    'first_name', 'middle_name', 'last_name', 'extension_name',
                ])),
                'current_birthdate' => $beneficiary->birthdate?->format('Y-m-d') ?? '',
                'current_address' => $beneficiary->address ?? '',
                'current_batch' => $targetBatch->name,
                'restore_batch' => $sourceBatch->name,
                'original_excel_row' => $original->excel_row,
            ];
        }

        foreach ($datuRows as $row) {
            $datuExportRows[] = [
                'import_batch_id' => $row->import_batch_id,
                'excel_row' => $row->excel_row,
                'matched_beneficiary_id' => $row->matched_beneficiary_id,
                'source_id_number' => $row->payload['source_id_number'] ?? '',
                'first_name' => $row->payload['first_name'] ?? '',
                'middle_name' => $row->payload['middle_name'] ?? '',
                'last_name' => $row->payload['last_name'] ?? '',
                'extension_name' => $row->payload['extension_name'] ?? '',
                'birthdate' => $row->payload['birthdate'] ?? '',
                'address' => $row->payload['address'] ?? '',
                'contact_no' => $row->payload['contact_no'] ?? '',
                'barangay' => $row->payload['barangay'] ?? '',
                'city_municipality' => $row->payload['city_municipality'] ?? '',
                'province' => $row->payload['province'] ?? '',
                'district' => $row->payload['district'] ?? '',
            ];
        }

        $outputDir = storage_path('app/private/repairs');
        File::ensureDirectoryExists($outputDir);
        $stamp = now()->format('Ymd-His');

        $repairPath = $outputDir.DIRECTORY_SEPARATOR."pahamuddin-collision-{$stamp}.csv";
        $datuPath = $outputDir.DIRECTORY_SEPARATOR."datu-piang-source-records-{$stamp}.csv";

        $this->writeCsv($repairPath, array_keys($repairRows[0] ?? [
            'beneficiary_id' => '',
            'beneficiary_code' => '',
            'source_id_number' => '',
            'original_name' => '',
            'original_birthdate' => '',
            'original_address' => '',
            'current_name' => '',
            'current_birthdate' => '',
            'current_address' => '',
            'current_batch' => '',
            'restore_batch' => '',
            'original_excel_row' => '',
        ]), $repairRows);

        $this->writeCsv($datuPath, [
            'import_batch_id', 'excel_row', 'matched_beneficiary_id', 'source_id_number',
            'first_name', 'middle_name', 'last_name', 'extension_name', 'birthdate',
            'address', 'contact_no', 'barangay', 'city_municipality', 'province', 'district',
        ], $datuExportRows);

        $this->info('Affected records: '.$moved->count());
        $this->line('Restore report: '.$repairPath);
        $this->line('DATU PIANG source records: '.$datuPath);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            $this->error('Repair aborted because not every affected record has an original row.');

            return Command::FAILURE;
        }

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to restore the records.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($moved, $originalRows, $sourceBatchId, $sourceImportId): void {
            foreach ($moved as $beneficiary) {
                $original = $originalRows->get($beneficiary->source_id_number);
                $payload = $original->payload;

                unset(
                    $payload['beneficiary_code'],
                    $payload['birthdate_raw'],
                    $payload['contact_no_raw'],
                );

                $payload['beneficiary_batch_id'] = $sourceBatchId;
                $payload['import_batch_id'] = $sourceImportId;
                $payload['location_code'] = $this->resolveLocationCode($payload);

                $beneficiary->fill($payload);
                $beneficiary->duplicate_status = 'none';
                $beneficiary->duplicate_rule = null;
                $beneficiary->duplicate_note = null;
                $beneficiary->save();
            }
        });

        AuditLogger::log('beneficiary.import_collision_restored', 'beneficiary_batch', $sourceBatchId, [
            'source_import_batch_id' => $sourceImportId,
            'target_batch_id' => $targetBatchId,
            'restored_count' => $moved->count(),
            'beneficiary_ids' => $moved->pluck('id')->take(25)->values()->all(),
            'report_path' => $repairPath,
            'datu_piang_records_path' => $datuPath,
        ]);

        $this->info('Restored '.$moved->count().' records to "'.$sourceBatch->name.'".');
        $this->line('Source batch count: '.$sourceBatch->beneficiaries()->count());
        $this->line('Target batch count: '.$targetBatch->beneficiaries()->count());

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function name(array $payload): string
    {
        return trim(implode(' ', array_filter([
            $payload['first_name'] ?? null,
            $payload['middle_name'] ?? null,
            $payload['last_name'] ?? null,
            $payload['extension_name'] ?? null,
        ], fn ($value) => $value !== null && trim((string) $value) !== '')));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveLocationCode(array $payload): ?string
    {
        if (! empty($payload['location_code'])) {
            return $payload['location_code'];
        }

        $address = trim((string) ($payload['address'] ?? ''));

        if ($address === '') {
            return null;
        }

        $legacy = array_filter(
            [$payload['barangay'] ?? null, $payload['city_municipality'] ?? null, $payload['province'] ?? null, $payload['district'] ?? null],
            fn ($part) => $part !== null && trim((string) $part) !== '',
        );

        $needles = $legacy !== [] ? [implode(', ', $legacy), $address] : [$address];

        foreach ($needles as $needle) {
            $code = Location::query()
                ->whereRaw('UPPER(full_address) = ?', [mb_strtoupper($needle)])
                ->value('psgc_code');

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to write '.$path);
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers,
            ));
        }

        fclose($handle);
    }
}
