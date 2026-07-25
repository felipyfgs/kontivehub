<?php

namespace App\Services\Integra\Registrations;

use App\Contracts\SerproOperationExecutor;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproCapabilityDriver;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\FiscalRegistrationLink;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Services\Serpro\SerproContractService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Projeção idempotente de Cadastro/Vínculos (PNR Contador).
 */
final class RegistrationLinkProjectionService
{
    public const OPERATION_KEY = 'pnr_contador.consultar_vinculos';

    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 20;

    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly SerproContractService $contracts,
        private readonly CapabilityDriverResolver $drivers,
        private readonly ContributorCnpjResolver $contributors,
        private readonly RegistrationLinksResponseCodec $codec,
    ) {}

    /**
     * @return array{success: bool, count: int, simulated: bool, error_code?: string|null, error_message?: string|null}
     */
    public function refresh(Office $office, Client $client, ?string $correlationId = null): array
    {
        if ($client->office_id !== $office->id) {
            throw new RuntimeException('Cliente não pertence ao office.');
        }

        $lockKey = sprintf('fiscal:reglinks:%d:%d', $office->id, $client->id);
        $lock = Cache::lock($lockKey, 120);
        if (! $lock->get()) {
            return [
                'success' => false,
                'count' => 0,
                'simulated' => false,
                'error_code' => 'LOCK_BUSY',
                'error_message' => 'Refresh de vínculos já em andamento.',
            ];
        }

        try {
            $driver = $this->drivers->forCapability('registrations');
            if ($driver === SerproCapabilityDriver::Disabled) {
                return [
                    'success' => false,
                    'count' => 0,
                    'simulated' => false,
                    'error_code' => 'CAPABILITY_DISABLED',
                    'error_message' => 'Cadastro/Vínculos desabilitado.',
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

            $baseIdem = sprintf('reglinks:%d:%d:%s', $office->id, $client->id, now()->format('YmdHi'));
            $rowsByCnpj = [];
            $lastCnpj = null;
            $simulated = false;
            $sourceProvenance = FiscalSourceProvenance::SerproReal->value;

            for ($pageNumber = 1; $pageNumber <= self::MAX_PAGES; $pageNumber++) {
                $idem = $baseIdem.':page:'.$pageNumber;

                $businessData = ['size' => self::PAGE_SIZE];
                if ($lastCnpj !== null) {
                    $businessData['lastCnpj'] = $lastCnpj;
                }

                $response = $this->operations->execute(
                    office: $office,
                    client: $client,
                    operationKey: self::OPERATION_KEY,
                    businessData: $businessData,
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
                        'error_message' => 'Resposta sintética não pode gerar projeção de vínculos.',
                    ];
                }

                $simulated = false;
                $sourceProvenance = $response->sourceProvenance === FiscalSourceProvenance::SerproTrial->value
                    ? FiscalSourceProvenance::SerproTrial->value
                    : FiscalSourceProvenance::SerproReal->value;
                try {
                    $page = $this->codec->decode($response->dados);
                } catch (RuntimeException $exception) {
                    return [
                        'success' => false,
                        'count' => 0,
                        'simulated' => $simulated,
                        'error_code' => 'RESPONSE_LAYOUT_INVALID',
                        'error_message' => $exception->getMessage(),
                    ];
                }

                foreach ($page['rows'] as $row) {
                    $rowsByCnpj[$row['cnpj']] = $row;
                }

                $complete = $page['rows'] === []
                    || ($page['total_in_database'] !== null && count($rowsByCnpj) >= $page['total_in_database'])
                    || $page['last_cnpj'] === null;
                if ($complete) {
                    break;
                }
                if ($page['last_cnpj'] === $lastCnpj) {
                    return [
                        'success' => false,
                        'count' => 0,
                        'simulated' => $simulated,
                        'error_code' => 'PAGINATION_CURSOR_INVALID',
                        'error_message' => 'PNR Contador repetiu o cursor de paginação.',
                    ];
                }
                if ($pageNumber === self::MAX_PAGES) {
                    return [
                        'success' => false,
                        'count' => 0,
                        'simulated' => $simulated,
                        'error_code' => 'PAGINATION_LIMIT_EXCEEDED',
                        'error_message' => 'PNR Contador excedeu o limite defensivo de páginas.',
                    ];
                }
                $lastCnpj = $page['last_cnpj'];
            }

            $rows = array_values($rowsByCnpj);
            $evidence = substr(hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 0, 32);
            $now = now();
            DB::transaction(function () use ($rows, $office, $client, $contributor, $evidence, $simulated, $sourceProvenance, $now): void {
                foreach ($rows as $row) {
                    FiscalRegistrationLink::query()->updateOrCreate(
                        [
                            'office_id' => $office->id,
                            'client_id' => $client->id,
                            'link_key' => $row['cnpj'],
                        ],
                        [
                            'contributor_cnpj' => $contributor,
                            'status' => (string) ($row['situacaoCadastral'] ?? 'UNKNOWN'),
                            'evidence_version' => $evidence,
                            'operation_key' => self::OPERATION_KEY,
                            'source_provenance' => $sourceProvenance,
                            'is_simulated' => $simulated,
                            'summary_sanitized' => [
                                'keys' => array_keys($row),
                                'has_cnpj' => true,
                            ],
                            'observed_at' => $now,
                            'refreshed_at' => $now,
                        ],
                    );
                }
            });

            return [
                'success' => true,
                'count' => count($rows),
                'simulated' => $simulated,
            ];
        } finally {
            $lock->release();
        }
    }
}
