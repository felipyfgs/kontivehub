<?php

namespace App\Http\Resources;

use App\Models\TenantFiscalIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantFiscalIdentity */
final class TenantFiscalIdentityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cnpj' => $this->cnpj,
            'root_cnpj' => $this->root_cnpj,
            'status' => $this->status->value,
            'legal_name' => $this->legal_name,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
        ];
    }
}
