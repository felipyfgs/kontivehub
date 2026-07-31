<?php

namespace App\Http\Resources;

use App\DTO\Tenant\AutXmlOverviewData;
use App\Enums\SyncCursorStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AutXmlOverviewData */
final class TenantAutXmlOverviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $stream = TenantAutXmlStreamResource::make($this->stream)
            ->resolve($request);

        return [
            'identity' => $this->identity === null
                ? null
                : TenantFiscalIdentityResource::make($this->identity)
                    ->resolve($request),
            'tenant_cnpj' => $this->identity?->cnpj,
            'enrollments' => TenantAutXmlEnrollmentResource::collection(
                $this->enrollments->getCollection(),
            )->resolve($request),
            'cursor' => $this->cursor === null
                ? null
                : $this->cursorData(),
            'stream' => $stream,
            'coverage' => [
                'channel' => 'NFE_AUTXML_DISTDFE',
                'model' => '55',
                'label' => 'NF-e modelo 55',
                'not_retroactive' => true,
                'nfce_note' => 'NFC-e 65 e histórico/lacunas: import XML/ZIP.',
            ],
            'checklist' => [
                'coverage' => 'NF-e modelo 55 apenas (NFC-e 65 não usa DistDFe autXML)',
                'not_retroactive' => true,
                'erp_instruction' => 'Inclua o CNPJ completo do escritório na tag autXML do ERP antes de autorizar NF-e 55. Sem efeito retroativo.',
                'stream_activated' => $this->cursor?->activated_at !== null,
                'can_confirm_enrollment' => $this->stream->streamReady,
                'stream_reason' => $this->stream->streamReason,
                'quiet_hours' => $this->stream->quietHours,
                'ready_at' => $this->stream->readyAt,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'current_page' => $this->enrollments->currentPage(),
                'last_page' => $this->enrollments->lastPage(),
                'per_page' => $this->enrollments->perPage(),
                'total' => $this->enrollments->total(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function cursorData(): array
    {
        $cursor = $this->cursor;
        $circuitOpen = $cursor->last_cstat === '656'
            || $cursor->status === SyncCursorStatus::Blocked
            || (
                $cursor->next_sync_at !== null
                && $cursor->last_cstat === '656'
            );

        return [
            'id' => $cursor->id,
            'interested_root_cnpj' => $cursor->interested_root_cnpj,
            'query_cnpj' => $cursor->query_cnpj,
            'environment' => $cursor->environment,
            'channel' => $cursor->channel->value,
            'last_nsu' => $cursor->last_nsu,
            'max_nsu_seen' => $cursor->max_nsu_seen,
            'status' => $cursor->status->value,
            'last_cstat' => $cursor->last_cstat,
            'last_xmotivo' => $cursor->last_xmotivo,
            'consecutive_decode_failures' => $cursor->consecutive_decode_failures,
            'activated_at' => $cursor->activated_at?->toIso8601String(),
            'next_sync_at' => $cursor->next_sync_at?->toIso8601String(),
            'last_success_at' => $cursor->last_success_at?->toIso8601String(),
            'last_heartbeat_at' => $cursor->last_heartbeat_at?->toIso8601String(),
            'external_consumer_status' => $cursor->external_consumer_status,
            'circuit_open' => $circuitOpen,
        ];
    }
}
