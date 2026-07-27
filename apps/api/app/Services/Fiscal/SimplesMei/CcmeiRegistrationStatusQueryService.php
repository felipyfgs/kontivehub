<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\CcmeiRegistrationStatusObservation;
use App\Models\CcmeiRegistrationStatusProjection;
use App\Models\Client;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Histórico local e disparo manual explícito de CCMEISITCADASTRAL123. */
final class CcmeiRegistrationStatusQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /** @return array<string, mixed> */
    public function history(Tenant $tenant, Client $client): array
    {
        $this->assertClient($tenant, $client);
        $projection = CcmeiRegistrationStatusProjection::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('client_id', $client->id)->first();
        $observations = CcmeiRegistrationStatusObservation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('client_id', $client->id)->orderByDesc('observed_at')->orderByDesc('id')
            ->limit(50)->get()->map(static fn (CcmeiRegistrationStatusObservation $item): array => $item->toPublicArray())->values()->all();

        return ['client_id' => $client->id, 'current' => $projection?->toPublicArray(), 'history' => $observations,
            'provenance' => ['source' => 'local_projection', 'serpro_called' => false]];
    }

    /** @return array<string, mixed> */
    public function enqueueManualConsult(Tenant $tenant, Client $client, ?int $actorUserId): array
    {
        $this->assertClient($tenant, $client);
        $run = $this->runs->enqueueManual(
            tenant: $tenant, client: $client, systemCode: 'INTEGRA_MEI', serviceCode: 'CCMEI',
            operationCode: 'CONSULTAR_SITUACAO_CADASTRAL', competence: null, actorId: $actorUserId,
            correlationId: sprintf('ccmei-status-manual-%d-%s', $client->id, (string) Str::uuid()), dispatch: false,
        );
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['ccmei_registration_status_manual'] = true;
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return method_exists($run, 'toPublicArray') ? $run->toPublicArray() : [
            'id' => $run->id, 'client_id' => $run->client_id, 'status' => $run->status?->value ?? (string) $run->status,
            'service_code' => $run->service_code, 'operation_code' => $run->operation_code,
        ];
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
