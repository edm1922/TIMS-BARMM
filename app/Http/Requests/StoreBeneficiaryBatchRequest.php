<?php

namespace App\Http\Requests;

use App\Models\BeneficiaryBatch;
use App\Models\OfflineServerEncoder;
use App\Models\User;
use App\Services\BeneficiaryIdGenerator;
use App\Support\SyncIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBeneficiaryBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BeneficiaryBatch::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'beneficiary_code_series' => ['nullable', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && trim((string) $value) !== ''
                    && ! BeneficiaryIdGenerator::isValidSeries((string) $value)) {
                    $fail('The beneficiary ID series must end with a number so it can increment automatically.');
                }
            }],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('offline_server_encoders', 'id')->where(
                    fn ($query) => $query->where('role', User::ROLE_MANAGER)->where('is_active', true)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A batch name is required.',
            'name.max' => 'The batch name may not be longer than 100 characters.',
            'capacity.integer' => 'The beneficiary limit must be a whole number.',
            'capacity.min' => 'The beneficiary limit must be at least 1 (leave blank for no limit).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function batchPayload(): array
    {
        $managerId = $this->input('manager_id') ?: null;

        return [
            'origin_server_uuid' => SyncIdentity::originUuid(),
            'name' => trim((string) $this->input('name')),
            'description' => ($description = trim((string) $this->input('description'))) !== '' ? $description : null,
            'status' => BeneficiaryBatch::STATUS_OPEN,
            'capacity' => $this->input('capacity') !== null && $this->input('capacity') !== ''
                ? (int) $this->input('capacity')
                : null,
            'beneficiary_code_series' => ($series = trim((string) $this->input('beneficiary_code_series'))) !== ''
                ? $series
                : null,
            'created_by' => $this->user()?->id,
            'manager_id' => $managerId,
            'manager_uuid' => $managerId !== null
                ? OfflineServerEncoder::query()->whereKey($managerId)->value('encoder_uuid')
                : null,
        ];
    }
}
