<?php

namespace App\Http\Resources;

use App\Models\TenantFiscalIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantFiscalIdentity|null */
final class TenantFiscalIdentityStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'identity' => $this->resource === null
                ? null
                : TenantFiscalIdentityResource::make($this->resource)
                    ->resolve($request),
        ];
    }
}
