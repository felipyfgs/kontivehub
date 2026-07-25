<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalEventStatus;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalTrigger;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalLastUpdateEvent;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persiste e deduplica Eventos de Última Atualização;
 * direciona reconciliação sem varredura indiscriminada.
 */
final class FiscalLastUpdateEventService
{
    public function __construct(
        private readonly FiscalMonitoringScheduler $scheduler,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata  sanitizado (sem payload fiscal bruto)
     * @return array{event: FiscalLastUpdateEvent, duplicate: bool, run: ?FiscalMonitoringRun}
     */
    public function ingestAndDirect(
        Office $office,
        string $systemCode,
        string $eventType,
        ?Client $client = null,
        ?string $serviceCode = null,
        ?string $externalId = null,
        ?string $payloadDigest = null,
        ?CarbonImmutable $occurredAt = null,
        ?array $metadata = null,
        bool $enqueue = true,
        string $operationCode = 'MONITOR',
    ): array {
        $hash = FiscalIdempotency::eventHash(
            (int) $office->id,
            $systemCode,
            $eventType,
            $externalId,
            $payloadDigest,
        );

        $duplicate = false;
        $run = null;

        $event = DB::transaction(function () use (
            $office, $client, $systemCode, $serviceCode, $eventType, $externalId,
            $payloadDigest, $occurredAt, $metadata, $hash, $operationCode,
            &$duplicate, &$run
        ) {
            $existing = FiscalLastUpdateEvent::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('event_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $duplicate = true;
                if ($existing->status === FiscalEventStatus::Received) {
                    $existing->forceFill(['status' => FiscalEventStatus::Deduplicated])->save();
                }

                return $existing;
            }

            $event = FiscalLastUpdateEvent::query()->create([
                'office_id' => $office->id,
                'client_id' => $client?->id,
                'system_code' => strtoupper($systemCode),
                'service_code' => $serviceCode !== null ? strtoupper($serviceCode) : null,
                'event_type' => strtoupper($eventType),
                'event_external_id' => $externalId,
                'event_hash' => $hash,
                'payload_digest' => $payloadDigest,
                'status' => FiscalEventStatus::Received,
                'occurred_at' => $occurredAt,
                'received_at' => CarbonImmutable::now(),
                'metadata' => $metadata,
            ]);

            if ($client === null || $serviceCode === null) {
                $event->forceFill([
                    'status' => FiscalEventStatus::Ignored,
                    'processed_at' => CarbonImmutable::now(),
                ])->save();

                return $event;
            }

            if (! $this->scheduler->officeAllowsExternal((int) $office->id)) {
                $event->forceFill([
                    'status' => FiscalEventStatus::Ignored,
                    'processed_at' => CarbonImmutable::now(),
                    'metadata' => array_merge($metadata ?? [], ['ignore_reason' => 'SUBSCRIPTION_BLOCKED']),
                ])->save();

                return $event;
            }

            $slot = FiscalIdempotency::eventSlot($hash);
            $key = FiscalIdempotency::runKey(
                (int) $office->id,
                (int) $client->id,
                $systemCode,
                $serviceCode,
                $operationCode,
                null,
                FiscalTrigger::Event,
                $slot,
            );

            $existingRun = FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existingRun !== null) {
                $event->forceFill([
                    'status' => FiscalEventStatus::Directed,
                    'directed_run_id' => $existingRun->id,
                    'processed_at' => CarbonImmutable::now(),
                ])->save();
                $run = $existingRun;

                return $event;
            }

            $run = FiscalMonitoringRun::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'last_update_event_id' => $event->id,
                'system_code' => strtoupper($systemCode),
                'service_code' => strtoupper($serviceCode),
                'operation_code' => strtoupper($operationCode),
                'trigger' => FiscalTrigger::Event,
                'idempotency_key' => $key,
                'status' => FiscalRunStatus::Queued,
                'situation' => 'UNKNOWN',
                'coverage' => 'UNKNOWN',
                'mutability' => 'READ_ONLY',
                'correlation_id' => bin2hex(random_bytes(8)),
            ]);

            $event->forceFill([
                'status' => FiscalEventStatus::Directed,
                'directed_run_id' => $run->id,
            ])->save();

            return $event;
        });

        if ($enqueue && ! $duplicate && $run !== null && $run->status === FiscalRunStatus::Queued) {
            ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        }

        return [
            'event' => $event->fresh(),
            'duplicate' => $duplicate,
            'run' => $run?->fresh(),
        ];
    }
}
