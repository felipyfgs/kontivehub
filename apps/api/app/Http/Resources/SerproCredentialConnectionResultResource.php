<?php

namespace App\Http\Resources;

use App\DTO\Serpro\CredentialConnectionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CredentialConnectionResult */
final class SerproCredentialConnectionResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CredentialConnectionResult $result */
        $result = $this->resource;

        return [
            'evidence' => SerproCredentialConnectionEvidenceResource::make($result->evidence),
            'credential_version' => SerproCredentialVersionResource::make($result->credentialVersion),
        ];
    }
}
