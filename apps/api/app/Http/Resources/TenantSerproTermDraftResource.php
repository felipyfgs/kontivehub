<?php

namespace App\Http\Resources;

use App\DTO\Tenant\SerproTermDraftResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproTermDraftResult */
final class TenantSerproTermDraftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproTermDraftResult $result */
        $result = $this->resource;

        return TenantSerproAuthorizationResource::make(
            $result->authorization,
        )->resolve($request);
    }

    public function with(Request $request): array
    {
        /** @var SerproTermDraftResult $result */
        $result = $this->resource;

        return ['draft_sha256' => $result->draftSha256];
    }
}
