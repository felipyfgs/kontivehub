<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationFlowCreationData;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class StoreCommunicationFlowRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canManageFlows($actor);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:160',
                Rule::unique('communication_flows', 'name')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', app(CurrentTenant::class)->id())),
            ],
        ];
    }

    protected function prepareCommunicationValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function flowData(): CommunicationFlowCreationData
    {
        return new CommunicationFlowCreationData(
            name: (string) $this->validated('name'),
        );
    }
}
