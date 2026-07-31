<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CannedResponseFiltersData;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ListCommunicationCannedResponsesRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
    {
        foreach (['manage', 'is_active'] as $field) {
            if ($this->query->has($field)) {
                $this->merge([$field => $this->boolean($field)]);
            }
        }

        if ($this->query->has('q') && is_string($this->query('q'))) {
            $this->merge(['q' => trim($this->string('q')->toString())]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            return false;
        }

        $access = app(Access::class);

        return $this->manageMode()
            ? $access->canManageQuickReplies($actor)
            : $access->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'manage' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): CannedResponseFiltersData
    {
        $validated = $this->validated();
        $search = trim((string) ($validated['q'] ?? ''));

        return new CannedResponseFiltersData(
            manageMode: $this->manageMode(),
            isActive: array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : null,
            search: $search !== '' ? $search : null,
            perPage: (int) ($validated['per_page'] ?? 30),
            page: (int) ($validated['page'] ?? 1),
            paginated: $this->manageMode()
                || array_key_exists('page', $validated)
                || array_key_exists('per_page', $validated),
        );
    }

    private function manageMode(): bool
    {
        return $this->boolean('manage') || $this->has('is_active');
    }
}
