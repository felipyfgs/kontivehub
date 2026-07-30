<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\ConversationListPreferenceData;
use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateConversationListPreferencesRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canView($actor);
    }

    protected function prepareCommunicationValidation(): void
    {
        if ($this->has('status') && is_string($this->input('status'))) {
            $this->merge(['status' => strtoupper(trim($this->string('status')->toString()))]);
        }
        if ($this->has('sort_by') && is_string($this->input('sort_by'))) {
            $this->merge(['sort_by' => strtolower(trim($this->string('sort_by')->toString()))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'max:32'],
            'sort_by' => ['required', 'string', Rule::in(ConversationListSort::values())],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = strtoupper(trim((string) $this->input('status', '')));
            if ($status === 'ALL' || ConversationStatus::tryFrom($status) !== null) {
                return;
            }

            $validator->errors()->add('status', 'Status de preferência inválido.');
        });
    }

    public function preferenceData(): ConversationListPreferenceData
    {
        $validated = $this->validated();

        return new ConversationListPreferenceData(
            status: strtoupper((string) $validated['status']),
            sortBy: ConversationListSort::from((string) $validated['sort_by']),
        );
    }
}
