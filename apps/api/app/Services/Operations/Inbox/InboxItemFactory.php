<?php

namespace App\Services\Operations\Inbox;

use App\Models\Client;
use App\Models\Establishment;
use App\Models\SyncCursor;
use App\Services\Clients\CaptureEligibilityService;
use App\Support\LogSanitizer;

/**
 * Helpers compartilhados da projeção da inbox operacional (item, labels, cursor, sanitização).
 */
final class InboxItemFactory
{
    public const TYPE_SEVERITY = [
        'cursor_blocked' => 'critical',
        'cursor_error' => 'high',
        'sync_failed_recent' => 'high',
        'credential_expired' => 'critical',
        'credential_expiring_7d' => 'high',
        'credential_expiring_30d' => 'medium',
        'backup_stale' => 'high',
        'backup_never' => 'critical',
        'outbound_gap_exhausted' => 'high',
        'outbound_562_no_key' => 'medium',
        'outbound_656' => 'critical',
        'outbound_retrieval_expired' => 'high',
        'outbound_xml_divergent' => 'high',
        'outbound_authorized_unexpected' => 'critical',
        'outbound_cancel_failed' => 'critical',
        'svrs_nfce_a1' => 'high',
        'svrs_nfce_auth' => 'critical',
        'svrs_nfce_rate_limit' => 'medium',
        'svrs_nfce_multiple_queries' => 'critical',
        'svrs_nfce_budget' => 'medium',
        'svrs_nfce_contract_changed' => 'critical',
        'svrs_nfce_xml_signature' => 'critical',
        'svrs_nfce_divergent' => 'high',
        'svrs_nfce_breaker' => 'critical',
        'svrs_nfce_exhausted' => 'high',
        'quarantine_unmatched_issuer' => 'high',
        'quarantine_autxml_tag' => 'high',
        'quarantine_orphan_event' => 'medium',
        'quarantine_bytes_diverge' => 'critical',
        'quarantine_schema' => 'medium',
        'quarantine_other' => 'medium',
        'cte_a1_missing' => 'high',
        'cte_593' => 'critical',
        'cte_656' => 'critical',
        'cte_decode_failures' => 'high',
        'cte_heartbeat_stale' => 'medium',
        'cte_external_consumer' => 'high',
        'cte_unexpected_own_issuer' => 'high',
        'cte_redaction' => 'medium',
        'cte_conflict' => 'critical',
        'cte_pending_import' => 'medium',
        'mailbox_message' => 'medium',
        'mailbox_message_urgent' => 'critical',
        'serpro_termo_missing' => 'critical',
        'serpro_termo_expired' => 'critical',
        'serpro_token_expiring' => 'high',
        'serpro_auth_action_required' => 'critical',
        'serpro_auth_blocked' => 'critical',
        'proxy_power_expired' => 'high',
        'proxy_power_missing' => 'high',
        'source_unavailable' => 'high',
        'query_blocked' => 'high',
        'fiscal_pending' => 'medium',
        'guide_due_soon' => 'high',
        'usage_high' => 'medium',
        'usage_franchise_exceeded' => 'high',
        'mutation_unknown_result' => 'critical',
        'parsing_alert' => 'medium',
        'sitfis_run_completed' => 'low',
        'sitfis_run_failed' => 'high',
    ];

    public function __construct(
        private readonly CaptureEligibilityService $eligibility,
    ) {}

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    public function item(
        string $type,
        string $title,
        string $body,
        array $reasons,
        ?int $clientId,
        ?int $establishmentId,
        string $occurredAt,
        ?InboxCapabilities $role,
        ?Establishment $establishment,
        ?SyncCursor $cursor,
    ): array {
        $severity = self::TYPE_SEVERITY[$type] ?? 'medium';
        // Inclui cursor_id + environment para não colidir multi-ambiente no mesmo establishment.
        $subject = implode(':', array_filter([
            $type,
            $clientId !== null ? 'c'.$clientId : null,
            $establishmentId !== null ? 'e'.$establishmentId : null,
            $cursor?->id !== null ? 'cur'.$cursor->id : null,
            is_string($cursor?->environment) && $cursor->environment !== ''
                ? 'env'.$cursor->environment
                : null,
        ], fn ($part) => $part !== null && $part !== ''));
        $id = substr(hash('sha256', $subject), 0, 32);

        $links = [];
        if ($clientId !== null) {
            $links['client'] = '/clients/'.$clientId;
            $links['sync'] = '/clients/'.$clientId.'/sincronizacao';
            $links['credential'] = '/clients/'.$clientId.'/certificado';
        }

        $actions = [
            ['type' => 'open', 'label' => 'Abrir'],
        ];

        if (
            $this->canTriggerSync($role)
            && $establishment !== null
            && in_array($type, ['cursor_error', 'sync_failed_recent'], true)
        ) {
            $eval = $this->eligibility->evaluate($establishment, $cursor);
            if ($eval['eligible']) {
                $actions[] = [
                    'type' => 'trigger_sync',
                    'label' => 'Sincronizar',
                    'establishment_id' => $establishment->id,
                ];
            }
        }

        return [
            'id' => $id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'reasons' => $reasons,
            'client_id' => $clientId,
            'establishment_id' => $establishmentId,
            'occurred_at' => $occurredAt,
            'links' => $links,
            'actions' => $actions,
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    public function cteItem(
        string $type,
        string $title,
        string $body,
        array $reasons,
        ?int $clientId,
        ?int $establishmentId,
        string $occurredAt,
        InboxCapabilities|OfficeRole|null $role,
        bool $retryAllowed,
        ?int $cursorId,
        ?int $quarantineId = null,
    ): array {
        $severity = self::TYPE_SEVERITY[$type] ?? 'medium';
        $subject = implode(':', array_filter([
            $type,
            $clientId !== null ? 'c'.$clientId : null,
            $establishmentId !== null ? 'e'.$establishmentId : null,
            $cursorId !== null ? 'cur'.$cursorId : null,
            $quarantineId !== null ? 'q'.$quarantineId : null,
        ]));
        $id = substr(hash('sha256', $subject), 0, 32);

        $actions = [['type' => 'open', 'label' => 'Abrir']];
        if ($retryAllowed && $cursorId !== null && $this->canTriggerSync($role)) {
            $actions[] = [
                'type' => 'repair_known_nsu',
                'label' => 'Reparo NSU conhecido',
                'cursor_id' => $cursorId,
                'requires_known_nsu' => true,
            ];
        }
        if ($quarantineId !== null && $this->canManageClients($role)) {
            $actions[] = [
                'type' => 'resolve_quarantine',
                'label' => 'Resolver',
                'quarantine_id' => $quarantineId,
            ];
        }

        $links = ['health' => '/health?type='.$type];
        if ($clientId !== null) {
            $links['client'] = '/clients/'.$clientId;
            $links['sync'] = '/clients/'.$clientId.'/sincronizacao';
        }

        return [
            'id' => $id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'reasons' => $reasons,
            'client_id' => $clientId,
            'establishment_id' => $establishmentId,
            'occurred_at' => $occurredAt,
            'links' => $links,
            'actions' => $actions,
        ];
    }

    public function clientLabel(Client $client): string
    {
        $name = $client->display_name ?: $client->legal_name;

        return (string) $name;
    }

    private function canTriggerSync(?InboxCapabilities $role): bool
    {
        return $role?->canTriggerSync === true;
    }

    private function canManageClients(?InboxCapabilities $role): bool
    {
        return $role?->canManageClients === true;
    }

    public function sanitizeText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return mb_substr(LogSanitizer::scrubString($text), 0, 280);
    }

    public function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode((string) $offset), '+/', '-_'), '=');
    }

    public function decodeCursor(string $cursor): int
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || ! ctype_digit($raw)) {
            return 0;
        }

        return max(0, (int) $raw);
    }
}
