<?php

namespace App\Http\Requests\Communication;

use App\Support\CurrentOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $officeId = (int) app(CurrentOffice::class)->office()->id;

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:120',
                Rule::unique('communication_inboxes', 'name')
                    ->where(fn ($query) => $query
                        ->where('office_id', $officeId)),
            ],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'work_department_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }
}
