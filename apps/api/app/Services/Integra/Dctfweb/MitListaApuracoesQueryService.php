<?php

namespace App\Services\Integra\Dctfweb;

use App\DTO\Integra\MitListaApuracoesRequest;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\MitAssessment;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Ação explícita e leitura local da lista MIT 317, sempre tenant-scoped. */
final class MitListaApuracoesQueryService
{
    public function __construct(
        private readonly FiscalMonitoringRunService $runs,
    ) {}

    public function enqueue(
        Tenant $tenant,
        Client $client,
        MitListaApuracoesRequest $filters,
        ?int $actorId,
        ?string $correlationId = null,
    ): FiscalMonitoringRun {
        $this->assertClient($tenant, $client);

        $run = $this->runs->enqueueManual(
            tenant: $tenant,
            client: $client,
            systemCode: DctfwebCodes::SYSTEM_MIT,
            serviceCode: DctfwebCodes::SERVICE_MIT,
            operationCode: DctfwebCodes::OP_MIT_LISTAR_APURACOES,
            actorId: $actorId,
            correlationId: $correlationId,
            dispatch: false,
        );

        if ($run->wasRecentlyCreated) {
            $run->forceFill([
                'operation_key' => DctfwebCodes::OPERATION_KEY_MIT_LISTA_APURACOES,
                'progress' => array_merge(is_array($run->progress) ? $run->progress : [], [
                    'mit_lista_apuracoes' => $filters->toPayload(),
                ]),
            ])->save();

            ExecuteFiscalMonitoringRunJob::dispatch((int) $run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        }

        return $run->fresh() ?? $run;
    }

    /** @return Collection<int, MitAssessment> */
    public function localList(
        Tenant $tenant,
        Client $client,
        ?int $year = null,
    ): Collection {
        $this->assertClient($tenant, $client);
        if ($year !== null && ($year < 2000 || $year > 2100)) {
            throw new InvalidArgumentException('Ano da lista MIT inválido.');
        }

        return MitAssessment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->when($year !== null, fn ($q) => $q->where('period_key', 'like', sprintf('%04d-%%', $year)))
            ->orderByDesc('period_key')
            ->get()
            ->filter(static function (MitAssessment $apuracao): bool {
                $metadata = is_array($apuracao->metadata) ? $apuracao->metadata : [];

                return is_array($metadata['lista_apuracoes_317'] ?? null);
            })
            ->values();
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('Cliente não pertence ao escritório ativo.');
        }
    }
}
