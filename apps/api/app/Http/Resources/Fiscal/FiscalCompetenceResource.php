<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalCompetence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalCompetence */
final class FiscalCompetenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalCompetence $competence */
        $competence = $this->resource;

        return [
            'id' => $competence->id,
            'tenant_id' => $competence->tenant_id,
            'client_id' => $competence->client_id,
            'fiscal_category_id' => $competence->fiscal_category_id,
            'period_key' => $competence->period_key,
            'period_year' => $competence->period_year,
            'period_month' => $competence->period_month,
            'situation' => $competence->situation?->value,
            'coverage' => $competence->coverage?->value,
            'due_at' => $competence->due_at?->toIso8601String(),
            'closed_at' => $competence->closed_at?->toIso8601String(),
        ];
    }
}
