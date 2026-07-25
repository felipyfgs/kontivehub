<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\MailboxMessage;
use App\Models\MailboxMonitoringSetting;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalIdempotency;
use App\Support\FeatureFlags;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pós-LISTAR: enfileira DETALHE para mensagens sem corpo (cap + idempotência + fail-closed).
 */
final class MailboxDetailEnqueueService
{
    /**
     * @return list<FiscalMonitoringRun>
     */
    public function enqueueAfterList(Office $office, Client $client): array
    {
        if (! FeatureFlags::isModuleEnabled('mailbox', (int) $office->id)) {
            return [];
        }

        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->first();
        $limit = max(0, (int) ($setting?->auto_detail_limit
            ?? config('fiscal_monitoring.mailbox.max_detail_fetches_per_sync', 0)));
        if ($limit === 0) {
            return [];
        }

        $candidates = MailboxMessage::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('has_body', false)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '')
            ->orderByRaw('CASE WHEN official_read_indicator IS FALSE OR official_read_indicator = 0 THEN 0 ELSE 1 END')
            ->orderByDesc('received_at_official')
            ->orderByDesc('id')
            ->limit($limit * 3)
            ->get(['id', 'external_id']);

        $enqueued = [];
        foreach ($candidates as $message) {
            if (count($enqueued) >= $limit) {
                break;
            }

            $externalId = trim((string) $message->external_id);
            if ($externalId === '') {
                continue;
            }

            if ($this->hasOpenDetailRun($office, $client, $externalId)) {
                continue;
            }

            $run = $this->createDetailRun($office, $client, $externalId, (int) $message->id, true);
            if ($run !== null) {
                $enqueued[] = $run;
            }
        }

        return $enqueued;
    }

    public function enqueueOnDemand(Office $office, Client $client, MailboxMessage $message): FiscalMonitoringRun
    {
        if ((int) $message->office_id !== (int) $office->id || (int) $message->client_id !== (int) $client->id) {
            throw new \RuntimeException('MAILBOX_MESSAGE_SCOPE_MISMATCH');
        }
        if ($message->has_body) {
            throw new \RuntimeException('MAILBOX_MESSAGE_BODY_ALREADY_AVAILABLE');
        }
        $externalId = trim((string) $message->external_id);
        if ($externalId === '') {
            throw new \RuntimeException('MAILBOX_MESSAGE_EXTERNAL_ID_MISSING');
        }

        $existing = $this->existingDetailRun($office, $client, $externalId);
        if ($existing !== null) {
            return $existing;
        }

        return $this->createDetailRun($office, $client, $externalId, (int) $message->id, false)
            ?? throw new \RuntimeException('MAILBOX_DETAIL_ENQUEUE_FAILED');
    }

    private function hasOpenDetailRun(Office $office, Client $client, string $externalId): bool
    {
        return FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('system_code', 'INTEGRA_CAIXAPOSTAL')
            ->where('service_code', 'CAIXA_POSTAL')
            ->where('operation_code', 'DETALHE')
            ->whereIn('status', [FiscalRunStatus::Queued->value, FiscalRunStatus::Running->value])
            ->where('progress->external_message_id', $externalId)
            ->exists();
    }

    private function existingDetailRun(Office $office, Client $client, string $externalId): ?FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('system_code', 'INTEGRA_CAIXAPOSTAL')
            ->where('service_code', 'CAIXA_POSTAL')
            ->where('operation_code', 'DETALHE')
            ->where('progress->external_message_id', $externalId)
            ->orderByDesc('id')
            ->first();
    }

    private function createDetailRun(
        Office $office,
        Client $client,
        string $externalId,
        int $messageId,
        bool $automatic,
    ): ?FiscalMonitoringRun {
        // Uma chave estável por ISN impede DETALHE duplicado, inclusive em retries da UI.
        $slot = 'mailbox-detail:'
            .substr(hash('sha256', strtoupper(trim($externalId))), 0, 40);
        $key = FiscalIdempotency::runKey(
            (int) $office->id,
            (int) $client->id,
            'INTEGRA_CAIXAPOSTAL',
            'CAIXA_POSTAL',
            'DETALHE',
            null,
            FiscalTrigger::Event,
            $slot,
        );

        try {
            $run = FiscalMonitoringRun::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'system_code' => 'INTEGRA_CAIXAPOSTAL',
                'service_code' => 'CAIXA_POSTAL',
                'operation_code' => 'DETALHE',
                'operation_key' => 'caixa_postal.detalhe',
                'trigger' => FiscalTrigger::Event,
                'idempotency_key' => $key,
                'status' => FiscalRunStatus::Queued,
                'situation' => FiscalSituation::Unknown,
                'coverage' => FiscalCoverage::Unknown,
                'mutability' => FiscalMutability::ReadOnly,
                'correlation_id' => (string) Str::uuid(),
                'progress' => [
                    'external_message_id' => $externalId,
                    'message_id' => $messageId,
                    'mailbox_auto_detail' => $automatic,
                    'mailbox_on_demand' => ! $automatic,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('mailbox.detail_enqueue_failed', [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return null;
        }

        ExecuteFiscalMonitoringRunJob::dispatch($run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run;
    }
}
