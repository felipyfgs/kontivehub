<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalFinding */
final class FiscalFindingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalFinding $finding */
        $finding = $this->resource;

        return [
            'id' => $finding->id,
            'tenant_id' => $finding->tenant_id,
            'snapshot_id' => $finding->snapshot_id,
            'run_id' => $finding->run_id,
            'client_id' => $finding->client_id,
            'code' => $finding->code,
            'severity' => $finding->severity?->value,
            'title' => $finding->title,
            'detail' => $finding->detail,
            'situation' => $finding->situation?->value,
            'is_active' => $finding->is_active,
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'created_at' => $finding->created_at?->toIso8601String(),
        ];
    }
}
