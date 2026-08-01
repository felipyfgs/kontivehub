<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\EventSyncFiltersData;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class SyncEventsRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ];
    }

    public function filters(): EventSyncFiltersData
    {
        $validated = $this->validated();

        return new EventSyncFiltersData(
            after: (int) ($validated['after'] ?? 0),
            limit: (int) ($validated['limit'] ?? 200),
        );
    }
}
