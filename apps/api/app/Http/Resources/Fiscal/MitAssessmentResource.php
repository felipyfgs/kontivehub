<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MitAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MitAssessment */
final class MitAssessmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MitAssessment $assessment */
        $assessment = $this->resource;
        $metadata = is_array($assessment->metadata)
            ? $assessment->metadata
            : [];
        $listaApuracao = is_array(
            $metadata['lista_apuracoes_317'] ?? null,
        )
            ? $metadata['lista_apuracoes_317']
            : null;

        return [
            'id' => $assessment->id,
            'tenant_id' => $assessment->tenant_id,
            'client_id' => $assessment->client_id,
            'competence_id' => $assessment->competence_id,
            'period_key' => $assessment->period_key,
            'encerramento_status' => $assessment->encerramento_status?->value,
            'situacao_status' => $assessment->situacao_status,
            'dctfweb_transmission_status' => $assessment
                ->dctfweb_transmission_status?->value,
            'situation' => $assessment->situation?->value,
            'coverage' => $assessment->coverage?->value,
            'encerrado_at' => $assessment->encerrado_at?->toIso8601String(),
            'observed_at' => $assessment->observed_at?->toIso8601String(),
            'current_snapshot_id' => $assessment->current_snapshot_id,
            'lista_apuracoes_317' => $listaApuracao,
            'stages' => [
                'mit_encerramento' => $assessment
                    ->encerramento_status?->value,
                'dctfweb_transmissao' => $assessment
                    ->dctfweb_transmission_status?->value,
            ],
        ];
    }
}
