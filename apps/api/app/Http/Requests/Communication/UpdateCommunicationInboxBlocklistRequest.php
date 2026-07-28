<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class UpdateCommunicationInboxBlocklistRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'identity_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('communication_identities', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', app(CurrentTenant::class)->id())),
            ],
            'action' => ['required', Rule::in(['BLOCK', 'UNBLOCK'])],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        $validated = $this->validated();

        return $this->gatewayOperation([
            'identity_id' => (int) $validated['identity_id'],
            'action' => (string) $validated['action'],
        ]);
    }
}
