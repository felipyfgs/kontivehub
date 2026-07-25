<?php

namespace App\Http\Requests\Communication;

use App\Enums\Communication\ConversationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'work_department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'priority' => ['sometimes', 'integer', 'between:0,100'],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
        ];
    }
}
