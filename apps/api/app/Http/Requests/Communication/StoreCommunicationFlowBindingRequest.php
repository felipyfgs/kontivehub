<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationFlowBindingCreationData;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class StoreCommunicationFlowBindingRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'inbox_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('communication_inboxes', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', app(CurrentTenant::class)->id())),
            ],
            'published_version_id' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function bindingData(): CommunicationFlowBindingCreationData
    {
        $validated = $this->validated();

        return new CommunicationFlowBindingCreationData(
            inboxId: (int) $validated['inbox_id'],
            publishedVersionId: isset($validated['published_version_id'])
                ? (int) $validated['published_version_id']
                : null,
            enabled: (bool) ($validated['enabled'] ?? false),
        );
    }
}
