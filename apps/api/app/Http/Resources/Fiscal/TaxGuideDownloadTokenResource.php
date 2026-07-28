<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Mutations\TaxGuideDownloadTokenData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuideDownloadTokenData */
final class TaxGuideDownloadTokenResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuideDownloadTokenData $token */
        $token = $this->resource;

        return [
            'token' => $token->token,
            'expires_at' => $token->expiresAt,
            'version_id' => $token->versionId,
            'download_path' => '/api/v1/fiscal/guides/downloads/'.$token->token,
        ];
    }
}
