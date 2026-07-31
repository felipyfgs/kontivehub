<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\FlowUpdateData;
use App\Enums\Communication\FlowStatus;
use App\Models\CommunicationFlow;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class UpdateCommunicationFlowRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:160',
                Rule::unique('communication_flows', 'name')
                    ->ignore($this->route('flow') instanceof CommunicationFlow
                        ? $this->route('flow')->id
                        : 0)
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', app(CurrentTenant::class)->id())),
            ],
            'status' => ['sometimes', 'required', Rule::enum(FlowStatus::class)],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareCommunicationValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function flowData(): FlowUpdateData
    {
        $validated = $this->validated();

        return new FlowUpdateData(
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            status: isset($validated['status'])
                ? FlowStatus::from((string) $validated['status'])
                : null,
            lockVersion: (int) $validated['lock_version'],
        );
    }
}
