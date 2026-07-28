<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationEventSyncFiltersData;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class SyncCommunicationEventsRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ];
    }

    public function filters(): CommunicationEventSyncFiltersData
    {
        $validated = $this->validated();

        return new CommunicationEventSyncFiltersData(
            after: (int) ($validated['after'] ?? 0),
            limit: (int) ($validated['limit'] ?? 200),
        );
    }
}
