<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CannedResponseMutationData;
use App\Models\CommunicationCannedResponse;
use App\Models\User;
use App\Rules\AllowedCommunicationCannedResponsePlaceholders;
use App\Services\Communication\Authorization\Access;

final class UpdateCannedResponseRequest extends TenantScopedRequest
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
            'title' => ['required', 'string', 'max:120'],
            'shortcut' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/'],
            'body' => ['required', 'string', 'max:4096', new AllowedCommunicationCannedResponsePlaceholders],
            'is_active' => ['sometimes', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function mutationData(): CannedResponseMutationData
    {
        $validated = $this->validated();

        return new CannedResponseMutationData(
            title: $validated['title'],
            shortcut: strtolower($validated['shortcut']),
            body: $validated['body'],
            isActive: array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : null,
            lockVersion: (int) $validated['lock_version'],
        );
    }
}
