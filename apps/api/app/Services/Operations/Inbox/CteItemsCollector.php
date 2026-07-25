<?php

namespace App\Services\Operations\Inbox;

use App\Enums\CaptureChannel;
use App\Enums\CredentialStatus;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Enums\SyncCursorStatus;
use App\Models\ChannelSyncCursor;
use App\Models\ClientCredential;
use App\Models\FiscalDocumentQuarantine;
use App\Models\OfficeDistributionCursor;
use Illuminate\Support\Collection;

/**
 * Inbox tipada CT-e (cursores cliente/autXML + quarentenas específicas).
 * Sem ações de portal automático; retry só se quiet/circuito permitir.
 */
final class CteItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $officeId, InboxCapabilities $capabilities): Collection
    {
        $items = collect();
        $threshold = (int) config('sefaz.decode_failure_threshold', 5);

        $clientCursors = ChannelSyncCursor::query()
            ->where('office_id', $officeId)
            ->where('channel', CaptureChannel::CteDistDfe->value)
            ->with(['establishment.client'])
            ->orderBy('id')
            ->limit(80)
            ->get();

        foreach ($clientCursors as $cursor) {
            $client = $cursor->establishment?->client;
            $est = $cursor->establishment;
            $label = $client ? $this->items->clientLabel($client) : 'estabelecimento';
            $quiet = $cursor->next_sync_at?->isFuture() ?? false;
            $blocked = $cursor->status === SyncCursorStatus::Blocked;
            $retryAllowed = ! $blocked && ! $quiet
                && $capabilities->canTriggerSync;

            if ($cursor->last_cstat === '656' || ($blocked && $cursor->last_cstat === '656')) {
                $items->push($this->items->cteItem(
                    type: 'cte_656',
                    title: 'CT-e circuito 656: '.$label,
                    body: 'Consumo indevido no DistDFe CT-e. Aguardar quiet ≥1h; sem retry manual até liberar.',
                    reasons: ['cte_656', 'cstat:656', 'chcur'.$cursor->id],
                    clientId: $client?->id,
                    establishmentId: $est?->id,
                    occurredAt: $cursor->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                    role: $capabilities,
                    retryAllowed: false,
                    cursorId: $cursor->id,
                ));
            }

            if ($cursor->last_cstat === '593') {
                $items->push($this->items->cteItem(
                    type: 'cte_593',
                    title: 'CT-e rejeição 593: '.$label,
                    body: 'Certificado/CNPJ divergente no DistDFe CT-e. Corrija A1 ou cadastro antes de retomar.',
                    reasons: ['cte_593', 'cstat:593', 'chcur'.$cursor->id],
                    clientId: $client?->id,
                    establishmentId: $est?->id,
                    occurredAt: $cursor->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                    role: $capabilities,
                    retryAllowed: false,
                    cursorId: $cursor->id,
                ));
            }

            if ((int) $cursor->consecutive_decode_failures >= max(1, $threshold - 1)) {
                $items->push($this->items->cteItem(
                    type: 'cte_decode_failures',
                    title: 'CT-e falhas de decode: '.$label,
                    body: 'Falhas consecutivas de Base64/GZip no mesmo NSU. Cursor preservado; sem avanço silencioso.',
                    reasons: ['cte_decode_failures', 'chcur'.$cursor->id],
                    clientId: $client?->id,
                    establishmentId: $est?->id,
                    occurredAt: $cursor->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                    role: $capabilities,
                    retryAllowed: $retryAllowed,
                    cursorId: $cursor->id,
                ));
            }
        }

        // A1 ausente para clientes com cursor CT-e ativo
        $clientIdsWithCte = $clientCursors->pluck('establishment.client_id')->filter()->unique();
        if ($clientIdsWithCte->isNotEmpty()) {
            $withA1 = ClientCredential::query()
                ->where('office_id', $officeId)
                ->whereIn('client_id', $clientIdsWithCte)
                ->where('status', CredentialStatus::Active)
                ->pluck('client_id')
                ->unique();
            foreach ($clientIdsWithCte->diff($withA1) as $clientId) {
                $items->push($this->items->cteItem(
                    type: 'cte_a1_missing',
                    title: 'CT-e sem A1 ativo',
                    body: 'Cursor CT-e existe mas não há credencial A1 ACTIVE do cliente. Sem portal automático.',
                    reasons: ['cte_a1_missing', 'c'.$clientId],
                    clientId: (int) $clientId,
                    establishmentId: null,
                    occurredAt: now()->toIso8601String(),
                    role: $capabilities,
                    retryAllowed: false,
                    cursorId: null,
                ));
            }
        }

        $officeCursors = OfficeDistributionCursor::query()
            ->where('office_id', $officeId)
            ->where('channel', CaptureChannel::CteAutXmlDistDfe->value)
            ->orderBy('id')
            ->limit(40)
            ->get();

        foreach ($officeCursors as $oc) {
            if ($oc->external_consumer_status === 'EXTERNAL_CONSUMER_CONFLICT') {
                $items->push($this->items->cteItem(
                    type: 'cte_external_consumer',
                    title: 'CT-e autXML: consumidor externo',
                    body: 'Stream do escritório em conflito com consumidor externo. Reconcilie ownership antes de retomar.',
                    reasons: ['cte_external_consumer', 'oc'.$oc->id],
                    clientId: null,
                    establishmentId: null,
                    occurredAt: $oc->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                    role: $capabilities,
                    retryAllowed: false,
                    cursorId: $oc->id,
                ));
            }
            $heartbeat = $oc->last_heartbeat_at;
            if ($heartbeat !== null && $heartbeat->lt(now()->subHours(36)) && $oc->status !== SyncCursorStatus::Idle) {
                $items->push($this->items->cteItem(
                    type: 'cte_heartbeat_stale',
                    title: 'CT-e autXML heartbeat atrasado',
                    body: 'Último heartbeat do stream autXML há mais de 36h.',
                    reasons: ['cte_heartbeat_stale', 'oc'.$oc->id],
                    clientId: null,
                    establishmentId: null,
                    occurredAt: $heartbeat->toIso8601String(),
                    role: $capabilities,
                    retryAllowed: ! ($oc->status === SyncCursorStatus::Blocked)
                        && ! ($oc->next_sync_at?->isFuture() ?? false),
                    cursorId: $oc->id,
                ));
            }
            if ($oc->last_cstat === '656') {
                $items->push($this->items->cteItem(
                    type: 'cte_656',
                    title: 'CT-e autXML circuito 656',
                    body: 'Consumo indevido no canal autXML do escritório. Quiet obrigatório.',
                    reasons: ['cte_656', 'autxml', 'oc'.$oc->id],
                    clientId: null,
                    establishmentId: null,
                    occurredAt: $oc->updated_at?->toIso8601String() ?? now()->toIso8601String(),
                    role: $capabilities,
                    retryAllowed: false,
                    cursorId: $oc->id,
                ));
            }
        }

        // Quarentenas CT-e tipadas (além do agrupamento genérico)
        $cteQuarantines = FiscalDocumentQuarantine::query()
            ->where('office_id', $officeId)
            ->where('resolution_status', QuarantineResolutionStatus::Open)
            ->where(function ($q): void {
                $q->where('model', '57')->orWhere('schema_family', 'like', '%CTe%')
                    ->orWhere('channel', CaptureChannel::CteDistDfe->value)
                    ->orWhere('channel', CaptureChannel::CteAutXmlDistDfe->value);
            })
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($cteQuarantines as $q) {
            $type = match ($q->reason) {
                QuarantineReason::UnexpectedOwnIssuerDocument => 'cte_unexpected_own_issuer',
                QuarantineReason::PendingImport => 'cte_pending_import',
                QuarantineReason::BytesDiverge => 'cte_conflict',
                QuarantineReason::OrphanEvent => 'quarantine_orphan_event',
                default => null,
            };
            if ($type === null) {
                // Redação: metadado ou qualidade implícita
                $meta = $q->metadata ?? [];
                if (($meta['artifact_quality'] ?? null) === 'AUTXML_REDACTED'
                    || ($meta['origin'] ?? null) === 'AUTXML_REDACTED') {
                    $type = 'cte_redaction';
                } else {
                    continue;
                }
            }

            $keyHint = $q->access_key ? mb_substr($q->access_key, 0, 8).'…' : 'sem chave';
            $items->push($this->items->cteItem(
                type: $type,
                title: $q->reason->label(),
                body: $this->items->sanitizeText('CT-e · '.$keyHint.' · '.$q->reason->label().'. Sem XML no painel.')
                    ?? $q->reason->label(),
                reasons: array_values(array_filter([
                    $type,
                    $q->reason->value,
                    $q->channel?->value,
                ])),
                clientId: null,
                establishmentId: null,
                occurredAt: $q->created_at?->toIso8601String() ?? now()->toIso8601String(),
                role: $capabilities,
                retryAllowed: false,
                cursorId: null,
                quarantineId: $q->id,
            ));
        }

        return $items->values();
    }
}
