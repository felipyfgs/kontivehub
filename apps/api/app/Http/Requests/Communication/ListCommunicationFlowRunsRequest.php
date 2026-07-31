<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\FlowRunFiltersData;
use App\Enums\Communication\FlowRunStatus;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Validation\Rule;

final class ListCommunicationFlowRunsRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
    {
        if ($this->query->has('active_only')) {
            $this->merge(['active_only' => $this->boolean('active_only')]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canViewFlows($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'flow_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(FlowRunStatus::class)],
            'active_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): FlowRunFiltersData
    {
        $validated = $this->validated();

        return new FlowRunFiltersData(
            flowId: isset($validated['flow_id']) ? (int) $validated['flow_id'] : null,
            status: $validated['status'] ?? null,
            activeOnly: $this->boolean('active_only'),
            perPage: (int) ($validated['per_page'] ?? 30),
            page: (int) ($validated['page'] ?? 1),
        );
    }
}
