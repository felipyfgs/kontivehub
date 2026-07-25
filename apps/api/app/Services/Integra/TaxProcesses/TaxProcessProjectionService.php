<?php

namespace App\Services\Integra\TaxProcesses;

use App\Contracts\SerproOperationExecutor;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproCapabilityDriver;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\FiscalTaxProcess;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Services\Serpro\SerproContractService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Projeção idempotente de Processos fiscais (e-Processo).
 */
final class TaxProcessProjectionService
{
    public const OPERATION_KEY = 'eprocesso.consultar_por_interessado';

    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly SerproContractService $contracts,
        private readonly CapabilityDriverResolver $drivers,
        private readonly ContributorCnpjResolver $contributors,
        private readonly TaxProcessesResponseCodec $codec,
    ) {}

    /**
     * @return array{success: bool, count: int, simulated: bool, error_code?: string|null, error_message?: string|null}
     */
    public function refresh(Office $office, Client $client, ?string $correlationId = null): array
    {
        if ($client->office_id !== $office->id) {
            throw new RuntimeException('Cliente não pertence ao office.');
        }

        $lockKey = sprintf('fiscal:taxproc:%d:%d', $office->id, $client->id);
        $lock = Cache::lock($lockKey, 120);
        if (! $lock->get()) {
            return [
                'success' => false,
                'count' => 0,
                'simulated' => false,
                'error_code' => 'LOCK_BUSY',
                'error_message' => 'Refresh de processos já em andamento.',
            ];
        }

        try {
            $driver = $this->drivers->forCapability('tax_processes');
            if ($driver === SerproCapabilityDriver::Disabled) {
                return [
                    'success' => false,
                    'count' => 0,
                    'simulated' => false,
                    'error_code' => 'CAPABILITY_DISABLED',
                    'error_message' => 'Processos fiscais desabilitados.',
                ];
            }

            $env = SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
                ?? SerproEnvironment::Trial;
            $contract = $this->contracts->activeFor($env);
            if ($contract === null || ! $contract->isUsable()) {
                return [
                    'success' => false,
                    'count' => 0,
                    'simulated' => false,
                    'error_code' => 'CONTRACT_UNAVAILABLE',
                    'error_message' => 'Contrato SERPRO indisponível.',
                ];
            }

            $auth = OfficeSerproAuthorization::query()
                ->where('office_id', $office->id)
                ->where('environment', $env->value)
                ->first();
            $author = (string) ($auth?->author_identity ?? $contract->contractor_cnpj);
            $contributor = $this->contributors->resolve($client);

            $idem = sprintf('taxproc:%d:%d:%s', $office->id, $client->id, now()->format('YmdHi'));

            $response = $this->operations->execute(
                office: $office,
                client: $client,
                operationKey: self::OPERATION_KEY,
                businessData: [],
                idempotencyKey: $idem,
                correlationId: $correlationId,
            );

            if (! $response->success) {
                return [
                    'success' => false,
                    'count' => 0,
                    'simulated' => $response->simulated,
                    'error_code' => $response->errorCode,
                    'error_message' => $response->errorMessage,
                ];
            }

            if ($response->hasSimulatedSource()) {
                return [
                    'success' => false,
                    'count' => 0,
                    'simulated' => false,
                    'error_code' => 'SIMULATED_SOURCE_REJECTED',
                    'error_message' => 'Resposta sintética não pode gerar projeção de processos.',
                ];
            }

            try {
                $rows = $this->codec->decode($response->dados);
            } catch (RuntimeException $exception) {
                return [
                    'success' => false,
                    'count' => 0,
                    'simulated' => $response->simulated,
                    'error_code' => 'RESPONSE_LAYOUT_INVALID',
                    'error_message' => $exception->getMessage(),
                ];
            }
            $evidence = substr(hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 0, 32);
            $now = now();
            $count = 0;

            foreach ($rows as $row) {
                $number = (string) $row['numeroDoProcesso'];
                FiscalTaxProcess::query()->updateOrCreate(
                    [
                        'office_id' => $office->id,
                        'client_id' => $client->id,
                        'process_number' => mb_substr($number, 0, 80),
                    ],
                    [
                        'contributor_cnpj' => $contributor,
                        'status' => (string) ($row['situacao'] ?? $row['status'] ?? 'OPEN'),
                        'evidence_version' => $evidence,
                        'operation_key' => self::OPERATION_KEY,
                        'source_provenance' => $response->sourceProvenance === FiscalSourceProvenance::SerproTrial->value
                            ? FiscalSourceProvenance::SerproTrial->value
                            : FiscalSourceProvenance::SerproReal->value,
                        'is_simulated' => false,
                        'summary_sanitized' => [
                            'keys' => array_keys($row),
                            'has_number' => true,
                        ],
                        'observed_at' => $now,
                        'refreshed_at' => $now,
                    ],
                );
                $count++;
            }

            return [
                'success' => true,
                'count' => $count,
                'simulated' => $response->simulated,
            ];
        } finally {
            $lock->release();
        }
    }
}
