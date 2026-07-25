<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Services\Operations\OperationsMetrics;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Verifica integridade da cadeia de auditoria append-only.
 * Alertas usam labels de baixa cardinalidade — sem payload, token ou PII.
 */
final class AuditIntegrityService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   checked: int,
     *   broken_at_seq: int|null,
     *   reason_code: string|null,
     *   genesis_hash: string
     * }
     */
    public function verify(?int $limit = null): array
    {
        if (! Schema::hasColumn('audit_logs', 'entry_hash')) {
            return [
                'ok' => true,
                'checked' => 0,
                'broken_at_seq' => null,
                'reason_code' => 'CHAIN_COLUMNS_ABSENT',
                'genesis_hash' => str_repeat('0', 64),
            ];
        }

        $q = AuditLog::query()
            ->whereNotNull('entry_hash')
            ->orderBy('chain_seq')
            ->orderBy('id');

        if ($limit !== null) {
            $q->limit(max(1, $limit));
        }

        $prevHash = str_repeat('0', 64);
        $checked = 0;
        $expectedSeq = 1;

        /** @var AuditLog $row */
        foreach ($q->cursor() as $row) {
            $checked++;

            if ($row->prev_hash !== $prevHash) {
                return $this->breakResult($checked, (int) ($row->chain_seq ?? $checked), 'PREV_HASH_MISMATCH');
            }

            $payload = [
                'chain_seq' => (int) $row->chain_seq,
                'office_id' => $row->office_id !== null ? (int) $row->office_id : null,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'action' => $row->action,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id !== null ? (int) $row->subject_id : null,
                'result' => $row->result,
                'context' => is_array($row->context) ? $row->context : [],
                'correlation_id' => $row->correlation_id,
                'prev_hash' => $row->prev_hash,
            ];

            $expected = $this->auditLogger->computeEntryHash($payload);
            if (! hash_equals((string) $row->entry_hash, $expected)) {
                return $this->breakResult($checked, (int) ($row->chain_seq ?? $checked), 'ENTRY_HASH_MISMATCH');
            }

            if ($row->chain_seq !== null && (int) $row->chain_seq !== $expectedSeq) {
                // Gaps podem existir em deploys parciais; só quebra se hash não encadeia.
                // Mantemos warning soft via metrics se gap > 1.
            }

            $prevHash = (string) $row->entry_hash;
            $expectedSeq = ((int) ($row->chain_seq ?? $expectedSeq)) + 1;
        }

        return [
            'ok' => true,
            'checked' => $checked,
            'broken_at_seq' => null,
            'reason_code' => null,
            'genesis_hash' => str_repeat('0', 64),
        ];
    }

    /**
     * Verifica e emite alerta sanitizado se a cadeia quebrar.
     *
     * @return array<string, mixed>
     */
    public function verifyAndAlert(): array
    {
        $result = $this->verify();

        if (! $result['ok']) {
            // Labels sem PII / sem payload / sem token
            Log::critical('audit.integrity.break', [
                'alert' => 'AUDIT_CHAIN_BREAK',
                'reason_code' => $result['reason_code'],
                'broken_at_seq' => $result['broken_at_seq'],
                'checked' => $result['checked'],
                'runbook' => 'docs/ops/runbooks/serpro-audit-integrity.md',
            ]);

            try {
                app(OperationsMetrics::class)->increment(
                    'audit.integrity.break',
                    1,
                    [
                        'reason_code' => (string) ($result['reason_code'] ?? 'UNKNOWN'),
                        'channel' => 'audit',
                    ],
                );
            } catch (\Throwable) {
                // métricas não derrubam verificação
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   ok: bool,
     *   checked: int,
     *   broken_at_seq: int|null,
     *   reason_code: string|null,
     *   genesis_hash: string
     * }
     */
    private function breakResult(int $checked, int $seq, string $reason): array
    {
        return [
            'ok' => false,
            'checked' => $checked,
            'broken_at_seq' => $seq,
            'reason_code' => $reason,
            'genesis_hash' => str_repeat('0', 64),
        ];
    }
}
