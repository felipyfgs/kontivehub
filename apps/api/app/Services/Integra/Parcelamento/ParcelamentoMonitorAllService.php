<?php

namespace App\Services\Integra\Parcelamento;

use App\Models\Client;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Enfileira o monitoramento completo em runs isoladas por modalidade produtiva. */
final class ParcelamentoMonitorAllService
{
    public function __construct(private readonly FiscalMonitoringRunService $runs) {}

    /**
     * @return array{
     *   requested_modalities:int,accepted:int,failed:int,
     *   results:list<array{modality:string,accepted:bool,run?:array<string,mixed>,error_code?:string}>
     * }
     */
    public function enqueueClient(
        Office $office,
        Client $client,
        ?int $actorId = null,
        ?string $correlationId = null,
        bool $dispatch = true,
    ): array {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }

        $baseCorrelation = $correlationId !== null && trim($correlationId) !== ''
            ? trim($correlationId)
            : (string) Str::uuid();
        $results = [];
        $accepted = 0;

        foreach (ParcelamentoServiceCatalog::supportedModalities() as $modality) {
            try {
                $run = $this->runs->enqueueManual(
                    office: $office,
                    client: $client,
                    systemCode: ParcelamentoServiceCatalog::SOLUTION,
                    serviceCode: $modality->value,
                    operationCode: 'MONITOR',
                    actorId: $actorId,
                    correlationId: mb_substr($baseCorrelation.':'.strtolower(str_replace('-', '_', $modality->value)), 0, 64),
                    dispatch: $dispatch,
                );
                $accepted++;
                $results[] = [
                    'modality' => $modality->value,
                    'accepted' => true,
                    'run' => $run->toPublicArray(),
                ];
            } catch (Throwable) {
                // Falha de uma modalidade não oculta nem bloqueia as demais.
                $results[] = [
                    'modality' => $modality->value,
                    'accepted' => false,
                    'error_code' => 'ENQUEUE_FAILED',
                ];
            }
        }

        return [
            'requested_modalities' => count(ParcelamentoServiceCatalog::supportedModalities()),
            'accepted' => $accepted,
            'failed' => count($results) - $accepted,
            'results' => $results,
        ];
    }
}
