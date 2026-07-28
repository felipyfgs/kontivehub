<?php

namespace App\Http\Resources;

use App\Models\TenantDistributionRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantDistributionRun */
final class TenantDistributionRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_distribution_cursor_id' => $this->tenant_distribution_cursor_id,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'from_nsu' => $this->from_nsu,
            'to_nsu' => $this->to_nsu,
            'pages_processed' => $this->pages_processed,
            'documents_persisted' => $this->documents_persisted,
            'documents_quarantined' => $this->documents_quarantined,
            'last_cstat' => $this->last_cstat,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
