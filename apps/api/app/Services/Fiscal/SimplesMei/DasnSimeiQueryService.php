<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Enums\MeiAutomationStatus;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\MeiAutomationAttempt;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DasnSimeiQueryService
{
    public const MANUAL_BATCH_LIMIT = 100;

    public function __construct(
        private readonly FiscalMonitoringRunService $runs,
    ) {}

    /** @return array<string, mixed> */
    public function history(Tenant $tenant, Client $client, ?int $calendarYear = null): array
    {
        $this->assertClient($tenant, $client);
        $attemptQuery = MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('operation_key', 'dasnsimei.consultimadecrec');
        $attempt = (clone $attemptQuery)->latest('id')->first();
        $dataAttempt = (clone $attemptQuery)
            ->where('status', MeiAutomationStatus::Succeeded->value)
            ->latest('id')
            ->first();

        $payload = is_array($dataAttempt?->result_payload_encrypted)
            ? $dataAttempt->result_payload_encrypted
            : [];
        $declarations = collect($payload['declarations'] ?? [])
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->when($calendarYear !== null, static fn ($items) => $items->where('calendar_year', $calendarYear))
            ->map(function (array $item) use ($dataAttempt): array {
                $artifactId = $item['receipt_artifact_id'] ?? null;
                $artifact = collect($dataAttempt?->toPublicArray()['artifacts'] ?? [])->first(
                    static fn (mixed $candidate): bool => is_array($candidate)
                        && ($candidate['id'] ?? null) === $artifactId,
                );

                return [
                    'calendar_year' => (int) $item['calendar_year'],
                    'status' => (string) $item['status'],
                    'transmitted_at' => $item['transmitted_at'] ?? null,
                    'declaration_type' => $item['declaration_type'] ?? null,
                    'special_situation' => $item['special_situation'] ?? null,
                    'special_situation_date' => $item['special_situation_date'] ?? null,
                    'pending' => ($item['pending'] ?? false) === true,
                    'coverage' => (string) ($item['coverage'] ?? 'SUMMARY'),
                    'artifact_attempt_id' => $artifact === null ? null : (int) $dataAttempt->id,
                    'receipt_available' => ($item['receipt_available'] ?? false) === true
                        && is_array($artifact),
                    'artifact' => is_array($artifact) ? $artifact : null,
                ];
            })
            ->values()
            ->all();
        $pendingYears = collect($declarations)
            ->filter(static fn (array $item): bool => $item['pending'])
            ->pluck('calendar_year')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'client_id' => (int) $client->id,
            'coverage' => (string) ($payload['coverage'] ?? 'UNKNOWN'),
            'declarations' => $declarations,
            'pending_years' => $pendingYears,
            'attempt' => $attempt?->toPublicArray(),
        ];
    }

    /** @param list<int> $clientIds
     * @return list<array<string, mixed>>
     */
    public function enqueueManualConsult(
        Tenant $tenant,
        array $clientIds,
        ?int $calendarYear,
        bool $includeFullReceipt,
        ?int $actorUserId,
    ): array {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
        if ($clientIds === [] || count($clientIds) > self::MANUAL_BATCH_LIMIT) {
            throw new HttpException(422, 'Lote DASN-SIMEI inválido.');
        }

        $clients = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');
        if ($clients->count() !== count($clientIds)) {
            throw new HttpException(422, 'Lote contém cliente inacessível ao escritório.');
        }

        $models = DB::transaction(function () use (
            $tenant,
            $clientIds,
            $clients,
            $calendarYear,
            $includeFullReceipt,
            $actorUserId,
        ): array {
            $created = [];
            foreach ($clientIds as $clientId) {
                /** @var Client $client */
                $client = $clients->get($clientId);
                $run = $this->runs->enqueueManual(
                    tenant: $tenant,
                    client: $client,
                    systemCode: 'INTEGRA_MEI',
                    serviceCode: 'DASN_SIMEI',
                    operationCode: 'CONSULTAR',
                    actorId: $actorUserId,
                    correlationId: sprintf('dasn-simei-%d-%s', $clientId, (string) Str::uuid()),
                    dispatch: false,
                );
                $run->forceFill(['progress' => array_filter([
                    'calendar_year' => $calendarYear,
                    'period_key' => $calendarYear === null ? null : (string) $calendarYear,
                    'include_full_receipt' => $includeFullReceipt,
                    'dasn_manual' => true,
                ], static fn (mixed $value): bool => $value !== null)])->save();
                $created[] = $run;
            }

            return $created;
        });

        return array_map(function ($run): array {
            ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
            $data = $run->refresh()->toPublicArray();
            unset($data['tenant_id'], $data['idempotency_key']);

            return $data;
        }, $models);
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
