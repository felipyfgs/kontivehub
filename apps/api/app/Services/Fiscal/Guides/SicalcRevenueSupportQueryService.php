<?php

namespace App\Services\Fiscal\Guides;

use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\SicalcRevenueSupportObservation;
use App\Models\SicalcRevenueSupportProjection;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Histórico local e disparo explícito para SICALC 5.2. */
final class SicalcRevenueSupportQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /** @return array<string, mixed> */
    public function history(Tenant $tenant, Client $client, ?string $revenueCode = null): array
    {
        $this->assertClient($tenant, $client);
        $revenueCode = $revenueCode !== null ? $this->normalizeRevenueCode($revenueCode) : null;
        $projections = SicalcRevenueSupportProjection::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('client_id', $client->id)
            ->when($revenueCode !== null, static fn ($q) => $q->where('revenue_code', $revenueCode))
            ->orderBy('revenue_code')->get()->map(static fn (SicalcRevenueSupportProjection $item): array => $item->toPublicArray())->values()->all();
        $observations = SicalcRevenueSupportObservation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('client_id', $client->id)
            ->when($revenueCode !== null, static fn ($q) => $q->where('revenue_code', $revenueCode))
            ->orderByDesc('observed_at')->orderByDesc('id')->limit(50)->get()
            ->map(static fn (SicalcRevenueSupportObservation $item): array => $item->toPublicArray())->values()->all();

        return ['client_id' => $client->id, 'revenue_code' => $revenueCode, 'current' => $projections,
            'history' => $observations, 'provenance' => ['source' => 'local_projection', 'serpro_called' => false]];
    }

    /** @return array<string, mixed> */
    public function enqueueManualConsult(Tenant $tenant, Client $client, string $revenueCode, ?int $actorUserId): array
    {
        $this->assertClient($tenant, $client);
        $revenueCode = $this->normalizeRevenueCode($revenueCode);
        $run = $this->runs->enqueueManual(
            tenant: $tenant, client: $client, systemCode: SicalcRevenueSupportAdapter::SYSTEM,
            serviceCode: SicalcRevenueSupportAdapter::SERVICE, operationCode: SicalcRevenueSupportAdapter::OPERATION,
            competence: null, actorId: $actorUserId,
            correlationId: sprintf('sicalc-support-%d-%s', $client->id, (string) Str::uuid()), dispatch: false,
        );
        $progress = is_array($run->progress) ? $run->progress : [];
        $progress['sicalc_revenue_support_manual'] = true;
        $progress['sicalc_revenue_code'] = $revenueCode;
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch($run->id)->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return method_exists($run, 'toPublicArray') ? $run->toPublicArray() : [
            'id' => $run->id, 'client_id' => $run->client_id, 'status' => $run->status?->value ?? (string) $run->status,
            'service_code' => $run->service_code, 'operation_code' => $run->operation_code,
        ];
    }

    private function normalizeRevenueCode(string $revenueCode): string
    {
        $revenueCode = trim($revenueCode);
        if (! preg_match('/^[0-9]{1,16}$/', $revenueCode)) {
            throw new InvalidArgumentException('codigo_receita deve conter apenas de 1 a 16 algarismos.');
        }

        return $revenueCode;
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }
}
