<?php

namespace App\Services\Operations;

use App\Services\Operations\Inbox\CredentialBackupItemsCollector;
use App\Services\Operations\Inbox\CteItemsCollector;
use App\Services\Operations\Inbox\CursorSyncItemsCollector;
use App\Services\Operations\Inbox\FiscalItemsCollector;
use App\Services\Operations\Inbox\InboxCapabilities;
use App\Services\Operations\Inbox\InboxItemFactory;
use App\Services\Operations\Inbox\MailboxItemsCollector;
use App\Services\Operations\Inbox\OutboundSvrsItemsCollector;
use App\Services\Operations\Inbox\QuarantineItemsCollector;
use App\Services\Operations\Inbox\SerproProxyUsageItemsCollector;
use Illuminate\Support\Collection;

/**
 * Projeção sob demanda da inbox operacional do escritório (sem fila persistida).
 *
 * Fachada pública: orquestra coletores por família, ordena e pagina.
 */
final class OperationsInboxBuilder
{
    public const TYPES = [
        'cursor_blocked',
        'cursor_error',
        'sync_failed_recent',
        'credential_expired',
        'credential_expiring_7d',
        'credential_expiring_30d',
        'backup_stale',
        'backup_never',
        // Canal MA outbound (nNF)
        'outbound_gap_exhausted',
        'outbound_562_no_key',
        'outbound_656',
        'outbound_retrieval_expired',
        'outbound_xml_divergent',
        'outbound_authorized_unexpected',
        'outbound_cancel_failed',
        // Canal SVRS NFC-e XML
        'svrs_nfce_a1',
        'svrs_nfce_auth',
        'svrs_nfce_rate_limit',
        'svrs_nfce_multiple_queries',
        'svrs_nfce_budget',
        'svrs_nfce_contract_changed',
        'svrs_nfce_xml_signature',
        'svrs_nfce_divergent',
        'svrs_nfce_breaker',
        'svrs_nfce_exhausted',
        // Quarentena autXML / import
        'quarantine_unmatched_issuer',
        'quarantine_autxml_tag',
        'quarantine_orphan_event',
        'quarantine_bytes_diverge',
        'quarantine_schema',
        'quarantine_other',
        // CT-e tipados
        'cte_a1_missing',
        'cte_593',
        'cte_656',
        'cte_decode_failures',
        'cte_heartbeat_stale',
        'cte_external_consumer',
        'cte_unexpected_own_issuer',
        'cte_redaction',
        'cte_conflict',
        'cte_pending_import',
        // Caixa Postal — alertas sanitizados (sem corpo/anexo)
        'mailbox_message',
        'mailbox_message_urgent',
        // KontiveHub / SERPRO (tenant-scoped)
        'serpro_termo_missing',
        'serpro_termo_expired',
        'serpro_token_expiring',
        'serpro_auth_action_required',
        'serpro_auth_blocked',
        'proxy_power_expired',
        'proxy_power_missing',
        'source_unavailable',
        'query_blocked',
        'fiscal_pending',
        'guide_due_soon',
        'usage_high',
        'usage_franchise_exceeded',
        'mutation_unknown_result',
        'parsing_alert',
        'sitfis_run_completed',
        'sitfis_run_failed',
    ];

    public const SEVERITIES = [
        'critical',
        'high',
        'medium',
        'low',
    ];

    private const SEVERITY_RANK = [
        'critical' => 0,
        'high' => 1,
        'medium' => 2,
        'low' => 3,
    ];

    public function __construct(
        private readonly InboxItemFactory $itemFactory,
        private readonly CursorSyncItemsCollector $cursorSync,
        private readonly CredentialBackupItemsCollector $credentialBackup,
        private readonly OutboundSvrsItemsCollector $outboundSvrs,
        private readonly QuarantineItemsCollector $quarantine,
        private readonly CteItemsCollector $cte,
        private readonly MailboxItemsCollector $mailbox,
        private readonly SerproProxyUsageItemsCollector $serproProxyUsage,
        private readonly FiscalItemsCollector $fiscal,
    ) {}

    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   meta: array{next_cursor: ?string, total_estimate: int, generated_at: string}
     * }
     */
    public function build(
        int $officeId,
        ?InboxCapabilities $capabilities = null,
        ?string $severity = null,
        ?string $type = null,
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        $items = $this->collectAll($officeId, $capabilities ?? new InboxCapabilities);

        if ($severity !== null && $severity !== '' && in_array($severity, self::SEVERITIES, true)) {
            $items = $items->filter(fn (array $item) => $item['severity'] === $severity)->values();
        }

        if ($type !== null && $type !== '' && in_array($type, self::TYPES, true)) {
            $items = $items->filter(fn (array $item) => $item['type'] === $type)->values();
        }

        $items = $items->sort(function (array $a, array $b): int {
            $rank = (self::SEVERITY_RANK[$a['severity']] ?? 9) <=> (self::SEVERITY_RANK[$b['severity']] ?? 9);
            if ($rank !== 0) {
                return $rank;
            }
            $time = strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
            if ($time !== 0) {
                return $time;
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        })->values();

        $total = $items->count();

        if ($cursor !== null && $cursor !== '') {
            $offset = $this->itemFactory->decodeCursor($cursor);
            if ($offset > 0) {
                $items = $items->slice($offset)->values();
            }
        }

        $limit = min(max($limit, 1), 100);
        $page = $items->take($limit)->values();
        $taken = $page->count();
        $startOffset = $cursor !== null && $cursor !== '' ? $this->itemFactory->decodeCursor($cursor) : 0;
        $nextOffset = $startOffset + $taken;
        $hasMore = $nextOffset < $total;

        return [
            'data' => $page->all(),
            'meta' => [
                'next_cursor' => $hasMore ? $this->itemFactory->encodeCursor($nextOffset) : null,
                'total_estimate' => $total,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Contagens agregadas (sem paginação).
     *
     * @return array{inbox_critical: int, inbox_high: int, inbox_total: int}
     */
    public function counts(int $officeId, ?InboxCapabilities $capabilities = null): array
    {
        $items = $this->collectAll($officeId, $capabilities ?? new InboxCapabilities);

        return [
            'inbox_critical' => $items->where('severity', 'critical')->count(),
            'inbox_high' => $items->where('severity', 'high')->count(),
            'inbox_total' => $items->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function collectAll(int $officeId, InboxCapabilities $capabilities): Collection
    {
        // Ordem de merge alinhada ao monólito (sort em build() é a fonte de verdade da página).
        return collect()
            ->merge($this->cursorSync->collect($officeId, $capabilities))
            ->merge($this->cte->collect($officeId, $capabilities))
            ->merge($this->credentialBackup->collect($officeId, $capabilities))
            ->merge($this->outboundSvrs->collect($officeId, $capabilities))
            ->merge($this->quarantine->collect($officeId, $capabilities))
            ->merge($this->mailbox->collect($officeId, $capabilities))
            ->merge($this->serproProxyUsage->collect($officeId, $capabilities))
            ->merge($this->fiscal->collect($officeId, $capabilities))
            ->values();
    }
}
