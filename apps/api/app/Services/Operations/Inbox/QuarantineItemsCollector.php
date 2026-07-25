<?php

namespace App\Services\Operations\Inbox;

use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Models\FiscalDocumentQuarantine;
use Illuminate\Support\Collection;

/**
 * Itens de quarentena abertos — sem XML, vault ou caminho.
 */
final class QuarantineItemsCollector
{
    public function __construct(
        private readonly InboxItemFactory $items,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(int $officeId, InboxCapabilities $capabilities): Collection
    {
        $rows = FiscalDocumentQuarantine::query()
            ->where('office_id', $officeId)
            ->where('resolution_status', QuarantineResolutionStatus::Open)
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        return collect($rows->map(function (FiscalDocumentQuarantine $q) use ($capabilities) {
            $type = match ($q->reason) {
                QuarantineReason::UnmatchedIssuer, QuarantineReason::EnrollmentMissing => 'quarantine_unmatched_issuer',
                QuarantineReason::AutXmlTagMissing, QuarantineReason::AutXmlTagDivergent => 'quarantine_autxml_tag',
                QuarantineReason::OrphanEvent => 'quarantine_orphan_event',
                QuarantineReason::BytesDiverge => 'quarantine_bytes_diverge',
                QuarantineReason::SchemaIncomplete, QuarantineReason::UnknownSchema => 'quarantine_schema',
                default => 'quarantine_other',
            };

            $keyHint = $q->access_key
                ? mb_substr($q->access_key, 0, 8).'…'
                : 'sem chave';
            $issuer = $q->issuer_cnpj ? 'emit '.$q->issuer_cnpj : 'emitente desconhecido';

            $actions = [
                ['type' => 'open', 'label' => 'Revisar'],
            ];
            if ($capabilities->canManageClients) {
                $actions[] = [
                    'type' => 'resolve_quarantine',
                    'label' => 'Resolver',
                    'quarantine_id' => $q->id,
                ];
            }

            $severity = InboxItemFactory::TYPE_SEVERITY[$type] ?? 'medium';
            $subject = implode(':', ['q', (string) $q->id, $type]);
            $id = substr(hash('sha256', $subject), 0, 32);

            return [
                'id' => $id,
                'type' => $type,
                'severity' => $severity,
                'title' => $q->reason->label(),
                'body' => $this->items->sanitizeText(
                    $issuer.' · '.$keyHint.' · '.$q->reason->label().'. Sem XML no painel.'
                ) ?? $q->reason->label(),
                'reasons' => array_values(array_filter([
                    $q->reason->value,
                    $q->model ? 'model:'.$q->model : null,
                    $q->channel?->value,
                ])),
                'client_id' => null,
                'establishment_id' => null,
                'occurred_at' => $q->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'links' => [
                    'quarantine' => '/health?type='.$type,
                ],
                'actions' => $actions,
            ];
        })->values()->all());
    }
}
