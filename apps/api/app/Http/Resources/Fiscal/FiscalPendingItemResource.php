<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalPendingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalPendingItem */
final class FiscalPendingItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalPendingItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'tenant_id' => $item->tenant_id,
            'client_id' => $item->client_id,
            'snapshot_id' => $item->snapshot_id,
            'run_id' => $item->run_id,
            'fiscal_category_id' => $item->fiscal_category_id,
            'competence_id' => $item->competence_id,
            'code' => $item->code,
            'title' => $item->title,
            'detail' => $item->detail,
            'severity' => $item->severity?->value,
            'status' => $item->status?->value,
            'situation' => $item->situation?->value,
            'due_at' => $item->due_at?->toIso8601String(),
            'resolved_at' => $item->resolved_at?->toIso8601String(),
            'logical_key' => $item->logical_key,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
