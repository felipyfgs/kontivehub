<?php

namespace App\Services\Operations\Inbox;

use App\Enums\DocumentAcquisitionSource;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundRetrievalStatus;
use App\Enums\OutboundSeriesStatus;
use App\Enums\SvrsNfceFailureReason;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Models\DocumentAcquisition;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\OutboundNumberState;
use App\Models\OutboundSeriesCursor;
use App\Services\Outbound\SvrsNfceCircuitBreaker;
use Illuminate\Support\Collection;

/**
 * Canal MA outbound (nNF) e SVRS NFC-e.
 */
final class OutboundSvrsItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $officeId, InboxCapabilities $role): Collection
    {
        return $this->outboundMaItems($officeId, $role)
            ->merge($this->svrsNfceItems($officeId, $role))
            ->values();
    }

    /**
     * Itens allowlisted do canal de saídas MA (posição nNF, sem last_nsu).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function outboundMaItems(int $officeId, InboxCapabilities $role): Collection
    {
        $items = collect();

        // Lacunas esgotadas
        $exhausted = OutboundNumberState::query()
            ->where('office_id', $officeId)
            ->where('status', OutboundNumberStatus::ExhaustedVisible)
            ->with(['seriesCursor.establishment.client'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($exhausted as $state) {
            $series = $state->seriesCursor;
            $establishment = $series?->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                continue;
            }
            $item = $this->items->item(
                type: 'outbound_gap_exhausted',
                title: 'Lacuna esgotada (nNF '.$state->nnf.'): '.$this->items->clientLabel($client),
                body: 'Série '.$state->series.' esgotou tentativas de consulta. Posição nNF — não é NSU. Requer revisão humana.',
                reasons: ['outbound_gap_exhausted', 'nnf:'.$state->nnf],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $state->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: $establishment,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'out:gap:'.$state->id), 0, 32);
            $items->push($item);
        }

        // 562 sem chave
        $noKey = OutboundNumberState::query()
            ->where('office_id', $officeId)
            ->where('status', OutboundNumberStatus::LimitedNoKey)
            ->with(['seriesCursor.establishment.client'])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        foreach ($noKey as $state) {
            $series = $state->seriesCursor;
            $establishment = $series?->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                continue;
            }
            $item = $this->items->item(
                type: 'outbound_562_no_key',
                title: '562 sem chave (nNF '.$state->nnf.'): '.$this->items->clientLabel($client),
                body: 'Consulta retornou limitação sem chNFe. Força bruta de cNF bloqueada. Use pacote oficial assistido.',
                reasons: ['outbound_562_no_key'],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $state->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: $establishment,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'out:562:'.$state->id), 0, 32);
            $items->push($item);
        }

        // 656 / séries bloqueadas
        $blocked = OutboundSeriesCursor::query()
            ->where('office_id', $officeId)
            ->whereIn('status', [OutboundSeriesStatus::Blocked, OutboundSeriesStatus::FiscalIncident])
            ->with(['establishment.client'])
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        foreach ($blocked as $series) {
            $establishment = $series->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                continue;
            }
            $type = $series->status === OutboundSeriesStatus::FiscalIncident
                ? 'outbound_authorized_unexpected'
                : 'outbound_656';
            $item = $this->items->item(
                type: $type,
                title: ($type === 'outbound_656' ? 'Bloqueio MA (cStat 656/série)' : 'Incidente fiscal MA').': '.$this->items->clientLabel($client),
                body: 'Série '.$series->series.' modelo '.$series->model->value.'. '.($this->items->sanitizeText($series->last_error) ?? 'Intervenção necessária. Kill switch pode estar ativo.'),
                reasons: [$type, 'series:'.$series->id],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $series->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: $establishment,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'out:series:'.$series->id.':'.$type), 0, 32);
            $items->push($item);
        }

        // Recuperação expirada
        $expired = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('status', OutboundRetrievalStatus::Expired)
            ->with(['establishment.client'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($expired as $req) {
            $establishment = $req->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                continue;
            }
            $item = $this->items->item(
                type: 'outbound_retrieval_expired',
                title: 'Recuperação MA expirada ('.$req->competence.'): '.$this->items->clientLabel($client),
                body: 'Solicitação de pacote OUT modelo '.$req->model->value.' expirou. Reenvie em modo assistido se necessário.',
                reasons: ['outbound_retrieval_expired'],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $req->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: $establishment,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'out:ret:'.$req->id), 0, 32);
            $items->push($item);
        }

        // XML divergente (quarentena)
        $divergent = DocumentAcquisition::query()
            ->where('office_id', $officeId)
            ->where('bytes_diverge_from_canonical', true)
            ->whereIn('source', [
                DocumentAcquisitionSource::MaOfficialPackage->value,
                DocumentAcquisitionSource::MaAssistedUpload->value,
                DocumentAcquisitionSource::MaM2mRetrieval->value,
                DocumentAcquisitionSource::SvrsNfceDownloadXmlDfe->value,
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($divergent as $acq) {
            $item = $this->items->item(
                type: 'outbound_xml_divergent',
                title: 'XML divergente MA: chave '.substr((string) $acq->access_key, 0, 10).'…',
                body: 'Mesma chave com bytes diferentes — quarentena. Canônico preservado. '.$this->items->sanitizeText($acq->quarantine_reason),
                reasons: ['outbound_xml_divergent'],
                clientId: null,
                establishmentId: $acq->establishment_id,
                occurredAt: $acq->created_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'out:div:'.$acq->id), 0, 32);
            $items->push($item);
        }

        // Cancelamento falho / incidente
        $cancelFailed = OutboundNumberState::query()
            ->where('office_id', $officeId)
            ->whereIn('status', [
                OutboundNumberStatus::FiscalIncident,
                OutboundNumberStatus::CancelPending,
            ])
            ->with(['seriesCursor.establishment.client'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($cancelFailed as $state) {
            $series = $state->seriesCursor;
            $establishment = $series?->establishment;
            $client = $establishment?->client;
            if ($establishment === null || $client === null) {
                continue;
            }
            $type = $state->status === OutboundNumberStatus::FiscalIncident
                ? 'outbound_authorized_unexpected'
                : 'outbound_cancel_failed';
            $item = $this->items->item(
                type: $type,
                title: 'Incidente mutante MA (nNF '.$state->nnf.'): '.$this->items->clientLabel($client),
                body: 'Estado '.$state->status->value.'. Canal bloqueado até intervenção humana. Documento/evento preservados.',
                reasons: [$type],
                clientId: $client->id,
                establishmentId: $establishment->id,
                occurredAt: $state->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: $establishment,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'out:mut:'.$state->id), 0, 32);
            $items->push($item);
        }

        return $items->values();
    }

    /**
     * Inbox tipada do canal SVRS NFC-e (sem chave completa / HTML / XML).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function svrsNfceItems(int $officeId, InboxCapabilities $role): Collection
    {
        $items = collect();

        $map = [
            SvrsNfceFailureReason::A1Unavailable->value => 'svrs_nfce_a1',
            SvrsNfceFailureReason::A1NotRelated->value => 'svrs_nfce_a1',
            SvrsNfceFailureReason::AuthForbidden->value => 'svrs_nfce_auth',
            SvrsNfceFailureReason::RateLimited->value => 'svrs_nfce_budget',
            SvrsNfceFailureReason::EgressBlockedMultipleQueries->value => 'svrs_nfce_multiple_queries',
            SvrsNfceFailureReason::ResponseContractChanged->value => 'svrs_nfce_contract_changed',
            SvrsNfceFailureReason::InvalidSignature->value => 'svrs_nfce_xml_signature',
            SvrsNfceFailureReason::InvalidXml->value => 'svrs_nfce_xml_signature',
            SvrsNfceFailureReason::IdentityMismatch->value => 'svrs_nfce_xml_signature',
            SvrsNfceFailureReason::DivergentBytes->value => 'svrs_nfce_divergent',
            SvrsNfceFailureReason::MaxAttempts->value => 'svrs_nfce_exhausted',
            SvrsNfceFailureReason::BreakerOpen->value => 'svrs_nfce_breaker',
        ];

        $recoveries = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Blocked,
                SvrsNfceRecoveryStatus::NotAvailableVisible,
                SvrsNfceRecoveryStatus::RetryScheduled,
            ])
            ->whereNotNull('failure_reason')
            ->with(['establishment.client', 'profile'])
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($recoveries as $req) {
            $reason = $req->failure_reason instanceof SvrsNfceFailureReason
                ? $req->failure_reason->value
                : (string) $req->failure_reason;
            $type = $map[$reason] ?? null;
            if ($type === null) {
                if ($req->recovery_status === SvrsNfceRecoveryStatus::NotAvailableVisible) {
                    $type = 'svrs_nfce_exhausted';
                } else {
                    continue;
                }
            }
            $establishment = $req->establishment;
            $client = $establishment?->client;
            $item = $this->items->item(
                type: $type,
                title: 'SVRS NFC-e: '.($req->failure_reason instanceof SvrsNfceFailureReason
                    ? $req->failure_reason->label()
                    : $reason),
                body: 'Recovery '.$req->recovery_status?->value.' — fallback assistido disponível. '
                    .$this->items->sanitizeText($req->last_error),
                reasons: [$type, 'origin:SVRS_PORTAL_BY_KEY'],
                clientId: $client?->id,
                establishmentId: $establishment?->id,
                occurredAt: $req->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: $establishment,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'svrs:rec:'.$req->id.':'.$type), 0, 32);
            $item['links'] = array_filter([
                'recovery_id' => $req->id,
                'profile_id' => $req->outbound_capture_profile_id,
                'establishment_id' => $req->establishment_id,
            ]);
            $items->push($item);
        }

        // Breaker global open
        try {
            $breaker = app(SvrsNfceCircuitBreaker::class);
            $global = $breaker->globalStatus();
            if (($global['state'] ?? 'closed') === 'open') {
                $item = $this->items->item(
                    type: 'svrs_nfce_breaker',
                    title: 'Circuit breaker SVRS global aberto',
                    body: 'Novos GET/POST bloqueados. Use fallback assistido; reset somente ADMIN+2FA após smoke.',
                    reasons: ['svrs_nfce_breaker', 'scope:global'],
                    clientId: null,
                    establishmentId: null,
                    occurredAt: now()->toIso8601String(),
                    role: $role,
                    establishment: null,
                    cursor: null,
                );
                $item['id'] = substr(hash('sha256', 'svrs:breaker:global'), 0, 32);
                $items->push($item);
            }
        } catch (\Throwable) {
            // ignore
        }

        // Divergentes SVRS
        $divergent = DocumentAcquisition::query()
            ->where('office_id', $officeId)
            ->where('bytes_diverge_from_canonical', true)
            ->whereIn('source', [
                DocumentAcquisitionSource::SvrsNfceDownloadXmlDfe->value,
                DocumentAcquisitionSource::SvrsNfe55DownloadXmlDfe->value,
            ])
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        foreach ($divergent as $acq) {
            $item = $this->items->item(
                type: 'svrs_nfce_divergent',
                title: 'XML SVRS divergente (chave mascarada)',
                body: 'Canônico preservado; revisão humana necessária.',
                reasons: ['svrs_nfce_divergent'],
                clientId: null,
                establishmentId: $acq->establishment_id,
                occurredAt: $acq->created_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $role,
                establishment: null,
                cursor: null,
            );
            $item['id'] = substr(hash('sha256', 'svrs:div:'.$acq->id), 0, 32);
            $items->push($item);
        }

        return $items->values();
    }
}
