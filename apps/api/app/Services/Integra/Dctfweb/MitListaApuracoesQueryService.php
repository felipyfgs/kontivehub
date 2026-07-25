<?php

namespace App\Services\Integra\Dctfweb;

use App\DTO\Integra\MitListaApuracoesRequest;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\MitApuracao;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use InvalidArgumentException;

/** Ação explícita e leitura local da lista MIT 317, sempre tenant-scoped. */
final class MitListaApuracoesQueryService
{
    public function __construct(
        private readonly FiscalMonitoringRunService $runs,
    ) {}

    public function enqueue(
        Office $office,
        Client $client,
        MitListaApuracoesRequest $filters,
        ?int $actorId,
        ?string $correlationId = null,
    ): FiscalMonitoringRun {
        $this->assertClient($office, $client);

        $run = $this->runs->enqueueManual(
            office: $office,
            client: $client,
            systemCode: DctfwebCodes::SYSTEM_MIT,
            serviceCode: DctfwebCodes::SERVICE_MIT,
            operationCode: DctfwebCodes::OP_MIT_LISTAR_APURACOES,
            actorId: $actorId,
            correlationId: $correlationId,
            dispatch: false,
        );

        $run->forceFill([
            'operation_key' => DctfwebCodes::OPERATION_KEY_MIT_LISTA_APURACOES,
            'progress' => array_merge(is_array($run->progress) ? $run->progress : [], [
                'mit_lista_apuracoes' => $filters->toPayload(),
            ]),
        ])->save();

        ExecuteFiscalMonitoringRunJob::dispatch((int) $run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->fresh() ?? $run;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function localList(Office $office, Client $client, ?int $year = null): array
    {
        $this->assertClient($office, $client);
        if ($year !== null && ($year < 2000 || $year > 2100)) {
            throw new InvalidArgumentException('Ano da lista MIT inválido.');
        }

        return MitApuracao::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->when($year !== null, fn ($q) => $q->where('period_key', 'like', sprintf('%04d-%%', $year)))
            ->orderByDesc('period_key')
            ->get()
            ->filter(static function (MitApuracao $apuracao): bool {
                $metadata = is_array($apuracao->metadata) ? $apuracao->metadata : [];

                return is_array($metadata['lista_apuracoes_317'] ?? null);
            })
            ->map(static fn (MitApuracao $apuracao): array => $apuracao->toPublicArray())
            ->values()
            ->all();
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new InvalidArgumentException('Cliente não pertence ao escritório ativo.');
        }
    }
}
