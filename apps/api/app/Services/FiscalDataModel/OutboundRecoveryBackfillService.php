<?php

namespace App\Services\FiscalDataModel;

use App\Support\FiscalDataModel\BackfillResult;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migra ma_outbound_retrieval_requests → outbound_recovery_cases/attempts.
 */
final class OutboundRecoveryBackfillService
{
    public function run(bool $dryRun = true, ?int $officeId = null): BackfillResult
    {
        if (! Schema::hasTable('outbound_recovery_cases')
            || ! Schema::hasTable('ma_outbound_retrieval_requests')) {
            return new BackfillResult(
                aggregate: FiscalModelAggregates::OUTBOUND,
                dryRun: $dryRun,
                processed: 0,
                mapped: 0,
                skipped: 0,
                rejected: 0,
                ambiguous: 0,
            );
        }

        $processed = 0;
        $mapped = 0;
        $skipped = 0;
        $query = DB::table('ma_outbound_retrieval_requests')->orderBy('id');
        if ($officeId !== null) {
            $query->where('office_id', $officeId);
        }

        foreach ($query->get() as $row) {
            $processed++;
            $existing = DB::table('outbound_recovery_cases')
                ->where('legacy_ma_request_id', $row->id)
                ->first();
            if ($existing !== null) {
                $skipped++;

                continue;
            }

            // Identidade estável por request legado (access_key pode repetir entre requests).
            $identity = sprintf('ma-request:%d', $row->id);

            $mapped++;
            if ($dryRun) {
                continue;
            }

            $clientId = $row->client_id ?? null;
            if ($clientId === null && ! empty($row->outbound_capture_profile_id) && Schema::hasTable('outbound_capture_profiles')) {
                $clientId = DB::table('outbound_capture_profiles')
                    ->where('id', $row->outbound_capture_profile_id)
                    ->value('client_id');
            }
            if ($clientId === null && ! empty($row->establishment_id) && Schema::hasTable('establishments')) {
                $clientId = DB::table('establishments')
                    ->where('id', $row->establishment_id)
                    ->value('client_id');
            }

            $urgency = $this->mapUrgency((string) ($row->urgency_band ?? $row->urgency ?? 'NORMAL'));
            $completeness = $this->mapCompleteness(
                (string) ($row->status ?? 'PENDING'),
                (string) ($row->recovery_status ?? ''),
            );

            $documentFamily = $row->document_type
                ?? (isset($row->model) ? (string) $row->model : null);

            $deadlineAt = $row->due_at
                ?? $row->deadline_at
                ?? $row->capture_deadline_at
                ?? null;

            $caseId = DB::table('outbound_recovery_cases')->insertGetId([
                'office_id' => $row->office_id,
                'client_id' => $clientId,
                'access_key' => $row->access_key ?? null,
                'document_family' => $documentFamily,
                'identity_key' => $identity,
                'urgency' => $urgency,
                'completeness' => $completeness,
                'deadline_at' => $deadlineAt,
                'legacy_ma_request_id' => $row->id,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => now(),
            ]);

            DB::table('fiscal_model_migration_maps')->updateOrInsert(
                [
                    'aggregate' => FiscalModelAggregates::OUTBOUND,
                    'source_table' => 'ma_outbound_retrieval_requests',
                    'source_id' => (string) $row->id,
                ],
                [
                    'target_table' => 'outbound_recovery_cases',
                    'target_id' => (string) $caseId,
                    'office_id' => $row->office_id,
                    'correlation_id' => sprintf('outbound-case:%d', $row->id),
                    'status' => 'MAPPED',
                    'notes_sanitized' => 'ma_request_to_case',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            if (Schema::hasTable('outbound_xml_recovery_attempts')) {
                $attempts = DB::table('outbound_xml_recovery_attempts')
                    ->where('ma_outbound_retrieval_request_id', $row->id)
                    ->orderBy('id')
                    ->get();
                foreach ($attempts as $attempt) {
                    DB::table('outbound_recovery_attempts')->insert([
                        'office_id' => $row->office_id,
                        'outbound_recovery_case_id' => $caseId,
                        'source' => (string) ($attempt->source ?? $attempt->channel ?? 'UNKNOWN'),
                        'request_tag' => $attempt->request_tag ?? null,
                        'routing_decision' => $attempt->routing_decision ?? null,
                        'result' => (string) ($attempt->result ?? $attempt->status ?? 'PENDING'),
                        'error_code_sanitized' => $attempt->error_code ?? null,
                        'started_at' => $attempt->started_at ?? null,
                        'finished_at' => $attempt->finished_at ?? null,
                        'legacy_attempt_id' => $attempt->id,
                        'created_at' => $attempt->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return new BackfillResult(
            aggregate: FiscalModelAggregates::OUTBOUND,
            dryRun: $dryRun,
            processed: $processed,
            mapped: $mapped,
            skipped: $skipped,
            rejected: 0,
            ambiguous: 0,
        );
    }

    /**
     * Mapeia urgency_band legado (PLANNED/ATTENTION/…) → CHECK LOW/NORMAL/HIGH/CRITICAL.
     */
    private function mapUrgency(string $raw): string
    {
        return match (strtoupper(trim($raw))) {
            'LOW', 'PLANNED' => 'LOW',
            'HIGH', 'CONTINGENCY', 'ATTENTION' => 'HIGH',
            'CRITICAL', 'OVERDUE' => 'CRITICAL',
            'CAPTURED', 'NORMAL', '' => 'NORMAL',
            default => 'NORMAL',
        };
    }

    /**
     * Mapeia status/recovery legado → OPEN|SATISFIED|FAILED|CANCELLED.
     */
    private function mapCompleteness(string $status, string $recoveryStatus): string
    {
        $s = strtoupper(trim($status));
        $r = strtoupper(trim($recoveryStatus));

        if (in_array($s, ['INGESTED', 'DOWNLOADED', 'READY', 'CAPTURED', 'SATISFIED', 'DONE', 'COMPLETE'], true)
            || in_array($r, ['INGESTED', 'CAPTURED', 'SATISFIED'], true)) {
            return 'SATISFIED';
        }

        if (in_array($s, ['FAILED', 'ERROR'], true) || $r === 'FAILED') {
            return 'FAILED';
        }

        if (in_array($s, ['EXPIRED', 'CANCELLED', 'CANCELED'], true)) {
            return $s === 'EXPIRED' ? 'FAILED' : 'CANCELLED';
        }

        return 'OPEN';
    }
}
