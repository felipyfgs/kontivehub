<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CannedResponseDuplicationData;
use App\Models\CommunicationCannedResponse;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class DuplicateCannedResponseRequest extends TenantScopedRequest
{
    protected function prepareScopedValidation(): void
    {
        foreach (['shortcut', 'title'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->string($field)->toString())]);
            }
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $canned = $this->route('canned');

        return $actor instanceof User
            && $canned instanceof CommunicationCannedResponse
            && app(Access::class)->canManageQuickReplies($actor, $canned);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'shortcut' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/'],
            'title' => ['sometimes', 'string', 'max:120'],
        ];
    }

    public function duplicationData(): CannedResponseDuplicationData
    {
        $validated = $this->validated();

        return new CannedResponseDuplicationData(
            shortcut: strtolower($validated['shortcut']),
            title: $validated['title'] ?? null,
        );
    }
}
