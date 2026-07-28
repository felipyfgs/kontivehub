<?php

namespace App\Http\Resources\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalPreviewResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsDigitalPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsDigitalPreviewResult $result */
        $result = $this->resource;

        return [
            'run' => (new FgtsDigitalRunResource($result->run))
                ->resolve($request),
            'preview_token' => $result->previewToken,
        ];
    }
}
