<?php

namespace App\Http\Requests;

use App\Models\BeneficiaryBatch;
use Illuminate\Foundation\Http\FormRequest;

class StoreBeneficiaryBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function batchPayload(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'description' => ($description = trim((string) $this->input('description'))) !== '' ? $description : null,
            'status' => BeneficiaryBatch::STATUS_OPEN,
            'created_by' => $this->user()?->id,
        ];
    }
}
