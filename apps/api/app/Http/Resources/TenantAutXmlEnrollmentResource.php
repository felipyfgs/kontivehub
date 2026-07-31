<?php

namespace App\Http\Resources;

use App\DTO\Tenant\AutXmlEnrollmentData;
use App\Enums\TenantAutXmlEnrollmentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AutXmlEnrollmentData */
final class TenantAutXmlEnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $establishment = $this->establishment;
        $enrollment = $this->enrollment;
        $client = $establishment->relationLoaded('client')
            ? $establishment->client
            : null;

        return [
            'id' => $enrollment?->id,
            'establishment_id' => $establishment->id,
            'establishment_cnpj' => $establishment->cnpj,
            'establishment_name' => $establishment->trade_name,
            'trade_name' => $establishment->trade_name,
            'client_id' => $establishment->client_id,
            'client_name' => $client?->display_name ?: $client?->legal_name,
            'status' => $enrollment?->status instanceof TenantAutXmlEnrollmentStatus
                ? $enrollment->status->value
                : ($enrollment?->status ?? 'NONE'),
            'activated_at' => $enrollment?->activated_at?->toIso8601String(),
            'first_seen_at' => $enrollment?->first_seen_at?->toIso8601String(),
            'last_seen_at' => $enrollment?->last_seen_at?->toIso8601String(),
            'observed' => $enrollment?->first_seen_at !== null,
            'channel_coverage' => 'NFE_55',
            'channel_coverage_label' => 'NF-e modelo 55 (autXML DistDFe)',
            'nfce_hint' => 'NFC-e modelo 65 não é capturada por este canal — use import XML/ZIP.',
            'erp_instruction' => 'Inclua o CNPJ completo do escritório na tag autXML do ERP antes de autorizar NF-e 55. Sem efeito retroativo sobre NSU já consumido.',
        ];
    }
}
