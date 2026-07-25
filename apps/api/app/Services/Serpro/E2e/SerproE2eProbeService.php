<?php

namespace App\Services\Serpro\E2e;

use App\DTO\Serpro\IntegraResponse;
use App\DTO\Serpro\SerproOperationCommand;
use App\Enums\SerproEnvironment;
use App\Enums\SerproReadinessGate;
use App\Models\Client;
use App\Models\Office;
use App\Models\SerproReadinessRun;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use App\Services\Serpro\SerproOperationService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probe e2e de operation_keys PRODUCTION via executor real (SerproOperationService).
 * Grava artifacts sanitizados e classifica resultado (negócio/async/bloqueio/falha).
 */
final class SerproE2eProbeService
{
    private const PRODUCTION_ENDPOINT = 'https://gateway.apiserpro.serpro.gov.br/integra-contador/v1';

    public function __construct(
        private readonly SerproOperationService $operations,
        private readonly OfficialServiceCatalogManifest $catalog,
        private readonly SerproE2ePayloadFactory $payloads,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function productionOperations(): array
    {
        $out = [];
        foreach ($this->catalog->load()['entries'] as $entry) {
            if (($entry['official_state'] ?? '') !== 'PRODUCTION') {
                continue;
            }
            $out[] = $entry;
        }

        usort($out, fn (array $a, array $b) => strcmp((string) $a['operation_key'], (string) $b['operation_key']));

        return $out;
    }

    /**
     * @param  array{protocol?: string|null, period?: string|null, context?: array<string, mixed>}  $ctx
     * @return array<string, mixed>
     */
    public function probe(
        Office $office,
        Client $client,
        string $operationKey,
        array $ctx = [],
        ?string $artifactDir = null,
    ): array {
        $entry = $this->findEntry($operationKey);
        $official = (string) ($entry['official_state'] ?? 'UNKNOWN');
        $isMutating = (bool) ($entry['is_mutating'] ?? false);
        $correlationId = (string) Str::uuid();
        $built = $this->payloads->forOperation($operationKey, $client, $ctx);
        $preflight = $this->canaryPreflight($office, $client, $operationKey, $official);

        $attempts = [];
        $response = null;
        $error = null;

        try {
            if (! $preflight['eligible']) {
                throw new \RuntimeException((string) $preflight['reason']);
            }
            $response = $this->runOnce(
                $office,
                $client,
                $operationKey,
                $built['business_data'],
                $built['payload'],
                $correlationId,
                idempotencySuffix: 'a',
            );
            $attempts[] = $this->sanitizeResponse($response);

            // 304 vazio: 1 retry com idempotency distinto (padrão SITFIS / cache SERPRO).
            if ($this->isEmptyNotModified($response)) {
                $response = $this->runOnce(
                    $office,
                    $client,
                    $operationKey,
                    $built['business_data'],
                    $built['payload'],
                    $correlationId,
                    idempotencySuffix: 'b-'.Str::lower(Str::random(6)),
                );
                $attempts[] = $this->sanitizeResponse($response);
            }
        } catch (Throwable $e) {
            if (! $preflight['eligible']) {
                $error = null;
            } else {
                $error = [
                    'class' => $e::class,
                    'message' => mb_substr($e->getMessage(), 0, 400),
                ];
            }
        }

        $classification = $this->classify($response, $error, $isMutating, $official, $preflight);
        $protocol = $this->extractProtocol($response);

        $result = [
            'operation_key' => $operationKey,
            'official_state' => $official,
            'platform_support' => $entry['platform_support'] ?? null,
            'route' => $entry['route'] ?? null,
            'id_sistema' => $entry['id_sistema'] ?? null,
            'id_servico' => $entry['id_servico'] ?? null,
            'is_mutating' => $isMutating,
            'office_id' => (int) $office->id,
            'client_id' => (int) $client->id,
            'correlation_id' => $correlationId,
            'classification' => $classification['status'],
            'classification_reason' => $classification['reason'],
            'evidence_provenance' => $preflight['eligible'] ? 'PRODUCTION_CANARY' : null,
            'evaluated' => true,
            'simulated' => $response?->simulated ?? null,
            'source_provenance' => $response?->sourceProvenance,
            'http_status' => $response?->httpStatus,
            'success' => $response?->success,
            'error_code' => $response?->errorCode,
            'business_status' => $response?->businessStatus,
            'has_dados' => $response?->dados !== null,
            'protocol_extracted' => $protocol,
            'notes' => $built['notes'],
            'attempts' => $attempts,
            'exception' => $error,
            'probed_at' => now()->toIso8601String(),
        ];

        if ($artifactDir !== null && $artifactDir !== '') {
            if (! is_dir($artifactDir)) {
                mkdir($artifactDir, 0775, true);
            }
            $safe = str_replace(['/', '\\'], '_', $operationKey);
            $path = rtrim($artifactDir, '/').'/'.$safe.'.json';
            file_put_contents(
                $path,
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
            $result['artifact_path'] = $path;
        }

        return $result;
    }

    /**
     * @param  list<string>|null  $onlyKeys
     * @return array{summary: array<string, int>, results: list<array<string, mixed>>, sitfis_protocol: string|null}
     */
    public function probeAllProduction(
        Office $office,
        Client $client,
        string $artifactDir,
        ?array $onlyKeys = null,
    ): array {
        $ops = $this->productionOperations();
        if ($onlyKeys !== null) {
            $set = array_fill_keys($onlyKeys, true);
            $ops = array_values(array_filter(
                $ops,
                fn (array $e) => isset($set[(string) $e['operation_key']]),
            ));
        }

        $results = [];
        $summary = [
            'total' => 0,
            'PASS_REAL_SYNC' => 0,
            'PASS_REAL_EMPTY' => 0,
            'PASS_REAL_ASYNC_COMPLETE' => 0,
            'PASS_REAL_CACHE' => 0,
            'INCOMPLETE_ASYNC' => 0,
            'BLOCKED_HUB' => 0,
            'BLOCKED_EXTERNAL' => 0,
            'FAIL_SERPRO' => 0,
            'ERROR' => 0,
            'SKIPPED_NON_PROD' => 0,
        ];

        $ctx = ['period' => now()->subMonth()->format('Y-m'), 'context' => []];
        $sitfisProtocol = null;

        // SITFIS: solicit antes do emit.
        $ordered = $ops;
        usort($ordered, function (array $a, array $b) {
            $ka = (string) $a['operation_key'];
            $kb = (string) $b['operation_key'];
            if ($ka === 'sitfis.solicitar_protocolo') {
                return -1;
            }
            if ($kb === 'sitfis.solicitar_protocolo') {
                return 1;
            }
            if ($ka === 'sitfis.emitir_relatorio') {
                return 1;
            }
            if ($kb === 'sitfis.emitir_relatorio') {
                return -1;
            }

            return strcmp($ka, $kb);
        });

        foreach ($ordered as $entry) {
            $key = (string) $entry['operation_key'];
            if ($key === 'sitfis.emitir_relatorio' && $sitfisProtocol !== null) {
                $ctx['protocol'] = $sitfisProtocol;
            }

            $row = $this->probe($office, $client, $key, $ctx, $artifactDir);
            $results[] = $row;
            $summary['total']++;
            $status = (string) $row['classification'];
            $summary[$status] = ($summary[$status] ?? 0) + 1;

            if ($key === 'sitfis.solicitar_protocolo' && is_string($row['protocol_extracted'] ?? null)) {
                $sitfisProtocol = $row['protocol_extracted'];
            }

            // Encadeia protocolo de eventos se vier nos dados (sanitizado só id curto).
            if (str_starts_with($key, 'eventosatualizacao.solic') && is_string($row['protocol_extracted'] ?? null)) {
                $ctx['context']['protocolo'] = $row['protocol_extracted'];
            }
        }

        $trackerPath = rtrim($artifactDir, '/').'/_tracker.json';
        file_put_contents(
            $trackerPath,
            json_encode([
                'summary' => $summary,
                'sitfis_protocol_present' => $sitfisProtocol !== null,
                'results' => array_map(fn (array $r) => [
                    'operation_key' => $r['operation_key'],
                    'classification' => $r['classification'],
                    'classification_reason' => $r['classification_reason'],
                    'http_status' => $r['http_status'],
                    'error_code' => $r['error_code'],
                    'simulated' => $r['simulated'],
                    'evidence_provenance' => $r['evidence_provenance'],
                    'artifact_path' => $r['artifact_path'] ?? null,
                ], $results),
                'generated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return [
            'summary' => $summary,
            'results' => $results,
            'sitfis_protocol' => $sitfisProtocol,
            'tracker_path' => $trackerPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $businessData
     * @param  array<string, mixed>  $payload
     */
    private function runOnce(
        Office $office,
        Client $client,
        string $operationKey,
        array $businessData,
        array $payload,
        string $correlationId,
        string $idempotencySuffix,
    ): IntegraResponse {
        $command = new SerproOperationCommand(
            office: $office,
            client: $client,
            operationKey: $operationKey,
            businessData: $businessData,
            payload: $payload,
            idempotencyKey: 'e2e-probe:'.$operationKey.':'.$idempotencySuffix.':'.now()->format('YmdHis'),
            correlationId: $correlationId,
            environment: (string) config('serpro.default_environment', 'TRIAL'),
        );

        return $this->operations->run($command);
    }

    private function isEmptyNotModified(?IntegraResponse $response): bool
    {
        if ($response === null) {
            return false;
        }
        if ($response->httpStatus === 304 || $response->errorCode === 'NOT_MODIFIED') {
            return true;
        }

        return $response->businessStatus === 'NOT_MODIFIED';
    }

    /**
     * @param  array<string, mixed>|null  $error
     * @return array{status: string, reason: string}
     */
    private function classify(
        ?IntegraResponse $response,
        ?array $error,
        bool $isMutating,
        string $official,
        array $preflight,
    ): array {
        if (! ($preflight['eligible'] ?? false)) {
            return ['status' => 'BLOCKED_EXTERNAL', 'reason' => (string) ($preflight['reason'] ?? 'CANARY_PROVENANCE_MISSING')];
        }
        if ($error !== null) {
            return ['status' => 'ERROR', 'reason' => 'exception:'.$error['class']];
        }
        if ($response === null) {
            return ['status' => 'ERROR', 'reason' => 'empty_response'];
        }

        $code = (string) ($response->errorCode ?? '');
        $hubBlocks = [
            'MUTATION_DISABLED',
            'FEATURE_DISABLED',
            'CAPABILITY_DISABLED',
            'KILL_SWITCH',
            'CIRCUIT_OPEN',
            'SUBSCRIPTION_BLOCKED',
            'CONTRACT_UNAVAILABLE',
            'AUTHORIZATION_MISSING',
            'AUTHORIZATION_ACTION_REQUIRED',
            'AUTHORIZATION_EXPIRED',
            'TOKEN_MISSING',
            'TOKEN_EXPIRED',
            'PROXY_POWER_MISSING',
            'PROXY_POWER_INSUFFICIENT',
            'PROXY_POWER_EXPIRED',
            'BUDGET_EXCEEDED',
            'RATE_LIMITED',
            'RATE_LIMIT_LOCAL',
            'RATE_LIMIT_NOT_CONFIGURED',
            'EGRESS_BLOCKED',
            'CONTRACT_EXPOSED_FLAG',
            'TECHNICAL_PARAM_REJECTED',
            'CONTRIBUTOR_IDENTITY_MISSING',
            'AUTHOR_IDENTITY_MISSING',
            'SERVICE_NOT_CATALOGED',
            'CAPABILITY_NOT_IMPLEMENTED',
            'CAPABILITY_NOT_EXECUTABLE',
            'MUTATING_DISABLED',
        ];

        if (in_array($code, $hubBlocks, true) || str_starts_with($code, 'CONTRACT_') || str_starts_with($code, 'PROXY_')) {
            return ['status' => 'BLOCKED_HUB', 'reason' => $code !== '' ? $code : 'hub_block'];
        }

        if ($isMutating && ! $response->success && $code === 'MUTATION_DISABLED') {
            return ['status' => 'BLOCKED_HUB', 'reason' => 'MUTATION_DISABLED'];
        }

        if ($response->isStillProcessing() || in_array($response->httpStatus, [202, 204], true)) {
            return ['status' => 'INCOMPLETE_ASYNC', 'reason' => 'terminal_async_result_required'];
        }

        if ($response->success
            && ! $response->hasSimulatedSource()
            && $response->sourceProvenance === 'SERPRO_REAL') {
            return ['status' => 'PASS_REAL_SYNC', 'reason' => 'production_canary_sync_success'];
        }

        if ($response->success) {
            return ['status' => 'FAIL_SERPRO', 'reason' => 'response_not_eligible_for_real_evidence'];
        }

        // 4xx/5xx SERPRO ou negócio com código remoto
        if (! $response->success) {
            return [
                'status' => 'FAIL_SERPRO',
                'reason' => $code !== '' ? $code : ('http_'.$response->httpStatus),
            ];
        }

        return ['status' => 'FAIL_SERPRO', 'reason' => 'unclassified_response'];
    }

    /**
     * @return array{eligible: bool, reason: string}
     */
    private function canaryPreflight(Office $office, Client $client, string $operationKey, string $official): array
    {
        if ($official !== 'PRODUCTION') {
            return ['eligible' => false, 'reason' => 'OFFICIAL_STATE_NOT_PRODUCTION'];
        }
        // Eventos exige envelope de contribuintes por tipo de pessoa. Enquanto
        // a coordenada PJ não estiver reconciliada e o probe não usar o fluxo
        // tipado, nunca deixe um canário genérico alcançar o transporte.
        if (str_starts_with($operationKey, 'eventosatualizacao.')) {
            return ['eligible' => false, 'reason' => 'EVENTOS_CONTRACT_UNRECONCILED'];
        }
        if (strtoupper((string) config('serpro.default_environment')) !== SerproEnvironment::Production->value) {
            return ['eligible' => false, 'reason' => 'ENVIRONMENT_NOT_PRODUCTION'];
        }

        $productionUrl = rtrim((string) config('serpro.environments.PRODUCTION.base_url'), '/');
        $apiUrl = rtrim((string) config('serpro.api.base_url'), '/');
        if ($productionUrl !== self::PRODUCTION_ENDPOINT || $apiUrl !== self::PRODUCTION_ENDPOINT) {
            return ['eligible' => false, 'reason' => 'CONTRACTED_ENDPOINT_NOT_CONFIRMED'];
        }

        $run = SerproReadinessRun::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('operation_key', $operationKey)
            ->where('environment', SerproEnvironment::Production->value)
            ->where('highest_gate', SerproReadinessGate::CanaryReady->value)
            ->where('trigger', 'BILLABLE_CANARY_PROMOTE')
            ->where('live_evidence', true)
            ->whereNotNull('serpro_contract_id')
            ->where('expires_at', '>', now())
            ->whereHas('evidences', fn ($query) => $query
                ->where('gate', SerproReadinessGate::CanaryReady->value)
                ->where('status', 'PASS')
                ->where('live_evidence', true)
                ->where('valid_until', '>', now()))
            ->latest('id')
            ->first();

        return $run === null
            ? ['eligible' => false, 'reason' => 'PRODUCTION_CANARY_APPROVAL_MISSING']
            : ['eligible' => true, 'reason' => 'PRODUCTION_CANARY_VERIFIED'];
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeResponse(IntegraResponse $response): array
    {
        return $response->toSanitizedArray();
    }

    private function extractProtocol(?IntegraResponse $response): ?string
    {
        if ($response === null) {
            return null;
        }
        $candidates = [];
        if (is_array($response->dados)) {
            $candidates[] = $response->dados;
        }
        $candidates[] = $response->body;
        foreach ($candidates as $payload) {
            if (! is_array($payload)) {
                continue;
            }
            foreach (['protocoloRelatorio', 'protocolo', 'protocol'] as $k) {
                if (! empty($payload[$k]) && is_scalar($payload[$k])) {
                    return trim((string) $payload[$k]);
                }
            }
            foreach (['dados', 'resultado', 'data'] as $wrap) {
                if (! isset($payload[$wrap])) {
                    continue;
                }
                $inner = $payload[$wrap];
                if (is_string($inner)) {
                    $decoded = json_decode($inner, true);
                    $inner = is_array($decoded) ? $decoded : null;
                }
                if (! is_array($inner)) {
                    continue;
                }
                foreach (['protocoloRelatorio', 'protocolo', 'protocol'] as $k) {
                    if (! empty($inner[$k]) && is_scalar($inner[$k])) {
                        return trim((string) $inner[$k]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findEntry(string $operationKey): array
    {
        foreach ($this->catalog->load()['entries'] as $entry) {
            if (($entry['operation_key'] ?? null) === $operationKey) {
                return $entry;
            }
        }

        return [];
    }
}
