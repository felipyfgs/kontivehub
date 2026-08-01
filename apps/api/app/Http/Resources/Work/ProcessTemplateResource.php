<?php

namespace App\Http\Resources\Work;

use App\Models\WorkProcessTemplate;
use App\Services\Work\ProcessTemplateProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProcessTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessTemplate $template */
        $template = $this->resource;

        return app(ProcessTemplateProjection::class)->build($template);
    }
}
