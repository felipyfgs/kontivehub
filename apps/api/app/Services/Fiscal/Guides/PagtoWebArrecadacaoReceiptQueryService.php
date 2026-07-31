<?php

namespace App\Services\Fiscal\Guides;

use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\PagtowebArrecadacaoReceipt;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Caso de uso manual síncrono: o número não entra em progress, job ou continuação. */
final class PagtoWebArrecadacaoReceiptQueryService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs, private readonly PagtoWebArrecadacaoReceiptCodec $codec) {}

    /** @return array<string, mixed> */
    public function history(Tenant $tenant, Client $client): array
    {
        $this->assertClient($tenant, $client);
        $items = PagtowebArrecadacaoReceipt::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('client_id', $client->id)
            ->latest('observed_at')->get()->map(static fn (PagtowebArrecadacaoReceipt $item) => $item->toPublicArray())->all();

        return ['client_id' => $client->id, 'items' => $items, 'provenance' => ['source' => 'local_projection', 'serpro_called' => false]];
    }

    /** @return array<string, mixed> */
    public function request(Tenant $tenant, Client $client, mixed $numeroDocumento, ?int $actorUserId): array
    {
        $this->assertClient($tenant, $client);
        $normalized = $this->codec->normalizeRequest($numeroDocumento);
        $run = $this->runs->enqueueManual(
            tenant: $tenant, client: $client, systemCode: PagtoWebArrecadacaoReceiptAdapter::SYSTEM,
            serviceCode: PagtoWebArrecadacaoReceiptAdapter::SERVICE, operationCode: PagtoWebArrecadacaoReceiptAdapter::OPERATION,
            actorId: $actorUserId, correlationId: sprintf('pagtoweb-receipt-%d-%s', $client->id, (string) Str::uuid()), dispatch: false,
        );
        $completed = $this->runs->execute($run->id, ['numeroDocumento' => $normalized['numeroDocumento']]);

        return $this->toPublicRun($completed);
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new HttpException(404, 'Cliente não encontrado no escritório atual.');
        }
    }

    /** @return array<string, mixed> */
    private function toPublicRun(FiscalMonitoringRun $run): array
    {
        return [
            'id' => $run->id,
            'operation_code' => $run->operation_code,
            'status' => $run->status?->value,
            'result' => $run->result?->value,
            'situation' => $run->situation?->value,
            'coverage' => $run->coverage?->value,
            'mutability' => $run->mutability?->value,
            'source_provenance' => $run->source_provenance?->value,
            'verification_state' => $run->verification_state?->value,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'created_at' => $run->created_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
