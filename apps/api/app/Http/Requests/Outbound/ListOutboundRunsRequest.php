<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundRunFilters;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class ListOutboundRunsRequest extends ViewOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'series_cursor_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('outbound_series_cursors', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', app(CurrentTenant::class)->id())),
            ],
        ];
    }

    public function filters(): OutboundRunFilters
    {
        $seriesCursorId = $this->validated('series_cursor_id');

        return new OutboundRunFilters(
            seriesCursorId: $seriesCursorId !== null ? (int) $seriesCursorId : null,
        );
    }
}
