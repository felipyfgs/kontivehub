<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundCaptureProfileFilters;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class ListOutboundProfilesRequest extends ViewOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'establishment_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('establishments', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', $tenantId)),
            ],
            'client_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('clients', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', $tenantId)),
            ],
        ];
    }

    public function filters(): OutboundCaptureProfileFilters
    {
        $validated = $this->validated();

        return new OutboundCaptureProfileFilters(
            establishmentId: isset($validated['establishment_id'])
                ? (int) $validated['establishment_id']
                : null,
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
        );
    }
}
