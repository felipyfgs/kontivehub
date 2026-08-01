<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class QueryInboxProfilePictureRequest extends InboxGatewayRequest
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
            'preview' => ['nullable', 'boolean'],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        $validated = $this->validated();

        return $this->gatewayOperation([
            'identity_id' => (int) $validated['identity_id'],
            'preview' => (bool) ($validated['preview'] ?? true),
        ]);
    }
}
