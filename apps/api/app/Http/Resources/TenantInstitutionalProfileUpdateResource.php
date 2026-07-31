<?php

namespace App\Http\Resources;

use App\DTO\Tenant\InstitutionalProfileUpdateResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InstitutionalProfileUpdateResult */
final class TenantInstitutionalProfileUpdateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InstitutionalProfileUpdateResult $result */
        $result = $this->resource;

        return [
            'profile' => TenantInstitutionalProfileResource::make(
                $result->profile,
            )->resolve($request),
            'cnpj_changed' => $result->cnpjChanged,
            'invalidated' => $result->invalidated,
        ];
    }
}
