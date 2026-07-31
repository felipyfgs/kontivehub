<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CannedResponseMutationData;
use App\Models\User;
use App\Rules\AllowedCommunicationCannedResponsePlaceholders;
use App\Services\Communication\Authorization\Access;

final class StoreCannedResponseRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
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

        return $actor instanceof User
            && app(Access::class)->canManageQuickReplies($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'shortcut' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/'],
            'body' => ['required', 'string', 'max:4096', new AllowedCommunicationCannedResponsePlaceholders],
            'is_active' => ['sometimes', 'boolean'],
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
            lockVersion: null,
        );
    }
}
