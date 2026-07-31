<?php

namespace App\Http\Resources;

use App\Models\WorkProcessTemplate;
use App\Services\Work\ProcessTemplateProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkProcessTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessTemplate $template */
        $template = $this->resource;

        return app(ProcessTemplateProjection::class)->build($template);
    }
}
