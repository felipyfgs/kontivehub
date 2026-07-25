<?php

namespace App\Services\Serpro\Catalog;

use App\Enums\SerproBillableClass;
use App\Enums\SerproFunctionalRoute;
use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use InvalidArgumentException;
use RuntimeException;

/**
 * Leitor/validador do manifesto versionado de serviços oficiais Integra Contador.
 *
 * @phpstan-type SourceRef array{url: string, sha256: string, kind?: string}
 * @phpstan-type SchemaBlock array{type?: string, fields?: list<mixed>, documented?: bool}
 * @phpstan-type ManifestEntry array{
 *   operation_key: string,
 *   id_sistema: string,
 *   id_servico: string,
 *   versao_sistema: string,
 *   route: string,
 *   auth_mode: string,
 *   proxy_rule: string,
 *   required_proxy_power: string|null,
 *   required_proxy_powers?: list<string>,
 *   official_state: string,
 *   platform_support: string,
 *   monitoring_module: string,
 *   label: string,
 *   is_mutating: bool,
 *   billable_class: string,
 *   dados_mode: string,
 *   async_policy: string,
 *   request_schema: SchemaBlock,
 *   response_schema: SchemaBlock,
 *   sources: list<SourceRef>,
 *   sequence?: int,
 *   catalog_code?: string
 * }
 * @phpstan-type Manifest array{
 *   manifest_version: string,
 *   source: string,
 *   verified_at: string,
 *   source_snapshots?: list<SourceRef>,
 *   expected_counts: array{total: int, PRODUCTION: int, PROSPECTION: int, UNDER_CONSTRUCTION: int, CANCELED: int},
 *   expected_route_counts?: array<string, int>,
 *   notes?: string,
 *   entries: list<ManifestEntry>
 * }
 */
final class OfficialServiceCatalogManifest
{
    public const DEFAULT_RELATIVE_PATH = 'resources/serpro/official-service-catalog.v2026-07-16.json';

    /** Sistemas inventariados sintéticos (não oficiais) — recusados. */
    private const PLACEHOLDER_SYSTEMS = [
        'INTEGRA_PROSPECTION',
        'INTEGRA_WIP',
        'INTEGRA_CANCELED',
        'PLACEHOLDER',
        'TBD',
        'XXX',
    ];

    private const ALLOWED_AUTH_MODES = [
        'CONTRACT_ONLY',
        'PROCURATOR_WHEN_REPRESENTING',
        'PROCURATOR_REQUIRED',
    ];

    private const ALLOWED_PROXY_RULES = [
        'NOT_APPLICABLE',
        'REQUIRED_WHEN_REPRESENTING',
        'REQUIRED',
        'EVENT_DEPENDENT',
    ];

    private const ALLOWED_ASYNC_POLICIES = [
        'HTTP_STATUS',
        'PROTOCOL_POLLING',
        'BATCH_POLLING',
        'STATUS_POLLING',
    ];

    private const ALLOWED_DADOS_MODES = [
        'EMPTY',
        'JSON_STRING',
    ];

    /**
     * @return Manifest
     */
    public function load(?string $absolutePath = null): array
    {
        $path = $absolutePath ?? base_path(self::DEFAULT_RELATIVE_PATH);
        if (! is_file($path)) {
            throw new RuntimeException("Manifesto SERPRO não encontrado: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException("Manifesto SERPRO ilegível: {$path}");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Manifesto SERPRO não é JSON válido.');
        }

        $this->assertStructure($decoded);

        /** @var Manifest $decoded */
        return $decoded;
    }

    /**
     * Valida contagens, unicidade, fontes oficiais e ausência de placeholders.
     *
     * @param  Manifest|null  $manifest
     * @return array{
     *   valid: bool,
     *   total: int,
     *   counts: array<string, int>,
     *   expected: array<string, int>,
     *   route_counts: array<string, int>,
     *   unique_operation_keys: bool,
     *   unique_coordinates: bool,
     *   errors: list<string>,
     *   manifest_version: string|null,
     *   sha256: string|null
     * }
     */
    public function validate(?array $manifest = null, ?string $absolutePath = null): array
    {
        $path = $absolutePath ?? base_path(self::DEFAULT_RELATIVE_PATH);
        $errors = [];
        $manifest ??= $this->load($path);

        $entries = $manifest['entries'] ?? [];
        if (! is_array($entries)) {
            return $this->result(false, 0, [], [], [], false, false, ['entries ausente'], null, null);
        }

        $counts = [
            'PRODUCTION' => 0,
            'PROSPECTION' => 0,
            'UNDER_CONSTRUCTION' => 0,
            'CANCELED' => 0,
        ];
        $routeCounts = [
            'Apoiar' => 0,
            'Consultar' => 0,
            'Declarar' => 0,
            'Emitir' => 0,
            'Monitorar' => 0,
        ];
        $keys = [];
        $coords = [];

        $topSources = $manifest['source_snapshots'] ?? [];
        if (! is_array($topSources) || $topSources === []) {
            $errors[] = 'source_snapshots ausente ou vazio no manifesto';
        } else {
            foreach ($topSources as $i => $snap) {
                if (! is_array($snap) || ! $this->isValidSource($snap)) {
                    $errors[] = "source_snapshots[{$i}] incompleto (url+sha256 obrigatórios)";
                }
            }
        }

        foreach ($entries as $i => $entry) {
            if (! is_array($entry)) {
                $errors[] = "entrada #{$i} não é objeto";

                continue;
            }

            foreach ([
                'operation_key', 'id_sistema', 'id_servico', 'versao_sistema', 'route',
                'auth_mode', 'proxy_rule', 'official_state', 'platform_support',
                'monitoring_module', 'label', 'is_mutating', 'billable_class',
                'dados_mode', 'async_policy', 'request_schema', 'response_schema', 'sources',
            ] as $field) {
                if (! array_key_exists($field, $entry)) {
                    $errors[] = "entrada #{$i} sem campo {$field}";
                }
            }

            $key = (string) ($entry['operation_key'] ?? '');
            if ($key === '') {
                $errors[] = "entrada #{$i} operation_key vazia";
            } elseif (str_starts_with($key, 'inventory.')) {
                $errors[] = "operation_key inventário sintético proibida: {$key}";
            } elseif (isset($keys[$key])) {
                $errors[] = "operation_key duplicada: {$key}";
            } else {
                $keys[$key] = true;
            }

            $idSistema = strtoupper((string) ($entry['id_sistema'] ?? ''));
            $idServico = (string) ($entry['id_servico'] ?? '');
            $versao = (string) ($entry['versao_sistema'] ?? '');

            if ($idSistema === '' || $idServico === '' || $versao === '') {
                $errors[] = "entrada {$key}: coordenadas incompletas";
            }
            if (in_array($idSistema, self::PLACEHOLDER_SYSTEMS, true)) {
                $errors[] = "entrada {$key}: id_sistema placeholder ({$idSistema})";
            }
            if (preg_match('/^(SERVPROSP|SERVWIP|SERVCANC|PLACEHOLDER|TBD)/i', $idServico) === 1) {
                $errors[] = "entrada {$key}: id_servico de inventário/placeholder ({$idServico})";
            }

            $coord = sprintf('%s|%s|%s', $idSistema, $idServico, $versao);
            if ($idSistema !== '' && $idServico !== '' && $versao !== '') {
                if (isset($coords[$coord])) {
                    $errors[] = "coordenadas duplicadas: {$coord}";
                } else {
                    $coords[$coord] = true;
                }
            }

            $state = (string) ($entry['official_state'] ?? '');
            if (isset($counts[$state])) {
                $counts[$state]++;
            } else {
                $errors[] = "estado oficial inválido em {$key}: {$state}";
            }
            if (SerproOfficialState::tryFrom($state) === null && $state !== '') {
                $errors[] = "SerproOfficialState inválido: {$state}";
            }

            $route = (string) ($entry['route'] ?? '');
            if (SerproFunctionalRoute::tryFrom($route) === null) {
                $errors[] = "rota inválida em {$key}: {$route}";
            } elseif (isset($routeCounts[$route])) {
                $routeCounts[$route]++;
            }

            $support = (string) ($entry['platform_support'] ?? '');
            if (SerproPlatformSupport::tryFrom($support) === null) {
                $errors[] = "platform_support inválido em {$key}: {$support}";
            }

            $billable = (string) ($entry['billable_class'] ?? '');
            if (SerproBillableClass::tryFrom($billable) === null) {
                $errors[] = "billable_class inválido em {$key}: {$billable}";
            }

            $authMode = (string) ($entry['auth_mode'] ?? '');
            if ($authMode !== '' && ! in_array($authMode, self::ALLOWED_AUTH_MODES, true)) {
                $errors[] = "auth_mode inválido em {$key}: {$authMode}";
            }

            $proxyRule = (string) ($entry['proxy_rule'] ?? '');
            if ($proxyRule !== '' && ! in_array($proxyRule, self::ALLOWED_PROXY_RULES, true)) {
                $errors[] = "proxy_rule inválido em {$key}: {$proxyRule}";
            }

            $async = (string) ($entry['async_policy'] ?? '');
            if ($async !== '' && ! in_array($async, self::ALLOWED_ASYNC_POLICIES, true)) {
                $errors[] = "async_policy inválido em {$key}: {$async}";
            }

            $dadosMode = (string) ($entry['dados_mode'] ?? '');
            if ($dadosMode !== '' && ! in_array($dadosMode, self::ALLOWED_DADOS_MODES, true)) {
                $errors[] = "dados_mode inválido em {$key}: {$dadosMode}";
            }

            $module = trim((string) ($entry['monitoring_module'] ?? ''));
            if ($module === '') {
                $errors[] = "monitoring_module ausente em {$key}";
            }

            $label = trim((string) ($entry['label'] ?? ''));
            if ($label === '') {
                $errors[] = "label vazio em {$key}";
            }

            if (! is_array($entry['request_schema'] ?? null)) {
                $errors[] = "request_schema ausente em {$key}";
            }
            if (! is_array($entry['response_schema'] ?? null)) {
                $errors[] = "response_schema ausente em {$key}";
            }

            $sources = $entry['sources'] ?? null;
            if (! is_array($sources) || $sources === []) {
                $errors[] = "sources ausente em {$key}";
            } else {
                $validSource = false;
                foreach ($sources as $src) {
                    if (is_array($src) && $this->isValidSource($src)) {
                        $validSource = true;
                    } else {
                        $errors[] = "source inválida em {$key}";
                    }
                }
                if (! $validSource) {
                    $errors[] = "entrada {$key} sem fonte oficial com url+sha256";
                }
            }

            // Operação marcada IMPLEMENTADO/VALIDADO exige evidência e estado produtivo
            $supportEnum = SerproPlatformSupport::tryFrom($support);
            if ($supportEnum !== null && $supportEnum->isExecutable()) {
                if ($state !== SerproOfficialState::Production->value) {
                    $errors[] = "entrada {$key}: executável sem estado PRODUCTION";
                }
                if (! is_array($entry['request_schema'] ?? null) || ! is_array($entry['response_schema'] ?? null)) {
                    $errors[] = "entrada {$key}: executável sem schema";
                }
            }

            // Não produtivas nunca são executáveis
            if ($state !== SerproOfficialState::Production->value
                && $supportEnum !== null
                && $supportEnum->isExecutable()
            ) {
                $errors[] = "entrada {$key}: não produtiva marcada como executável";
            }
        }

        $expected = $manifest['expected_counts'] ?? [
            'total' => 119,
            'PRODUCTION' => 98,
            'PROSPECTION' => 19,
            'UNDER_CONSTRUCTION' => 1,
            'CANCELED' => 1,
        ];

        $total = count($entries);
        if ($total !== (int) ($expected['total'] ?? 119)) {
            $errors[] = "total {$total} ≠ esperado {$expected['total']}";
        }
        foreach (['PRODUCTION', 'PROSPECTION', 'UNDER_CONSTRUCTION', 'CANCELED'] as $st) {
            if (($counts[$st] ?? 0) !== (int) ($expected[$st] ?? -1)) {
                $errors[] = "{$st}: {$counts[$st]} ≠ esperado {$expected[$st]}";
            }
        }

        $expectedRoutes = $manifest['expected_route_counts'] ?? null;
        if (is_array($expectedRoutes)) {
            foreach (['Apoiar', 'Consultar', 'Declarar', 'Emitir', 'Monitorar'] as $rt) {
                if (array_key_exists($rt, $expectedRoutes)
                    && ($routeCounts[$rt] ?? 0) !== (int) $expectedRoutes[$rt]
                ) {
                    $errors[] = "rota {$rt}: {$routeCounts[$rt]} ≠ esperado {$expectedRoutes[$rt]}";
                }
            }
        }

        $sha = is_file($path) ? hash_file('sha256', $path) : null;

        return $this->result(
            $errors === [],
            $total,
            $counts,
            $expected,
            $routeCounts,
            count($keys) === $total,
            count($coords) === $total,
            $errors,
            isset($manifest['manifest_version']) ? (string) $manifest['manifest_version'] : null,
            $sha,
        );
    }

    /**
     * Resolve entrada por operation_key.
     *
     * @param  Manifest  $manifest
     * @return ManifestEntry
     */
    public function findByOperationKey(array $manifest, string $operationKey): array
    {
        foreach ($manifest['entries'] as $entry) {
            if (($entry['operation_key'] ?? '') === $operationKey) {
                return $entry;
            }
        }

        throw new InvalidArgumentException("operation_key desconhecida: {$operationKey}");
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function isValidSource(array $source): bool
    {
        $url = trim((string) ($source['url'] ?? ''));
        $sha = strtolower(trim((string) ($source['sha256'] ?? '')));

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return false;
        }

        return (bool) preg_match('/^[a-f0-9]{64}$/', $sha);
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function assertStructure(array $decoded): void
    {
        foreach (['manifest_version', 'source', 'verified_at', 'expected_counts', 'entries'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException("Manifesto SERPRO sem campo obrigatório: {$field}");
            }
        }
        if (! is_array($decoded['entries'])) {
            throw new RuntimeException('Manifesto SERPRO: entries deve ser lista.');
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $routeCounts
     * @param  list<string>  $errors
     * @return array{
     *   valid: bool,
     *   total: int,
     *   counts: array<string, int>,
     *   expected: array<string, int>,
     *   route_counts: array<string, int>,
     *   unique_operation_keys: bool,
     *   unique_coordinates: bool,
     *   errors: list<string>,
     *   manifest_version: string|null,
     *   sha256: string|null
     * }
     */
    private function result(
        bool $valid,
        int $total,
        array $counts,
        array $expected,
        array $routeCounts,
        bool $uniqueKeys,
        bool $uniqueCoords,
        array $errors,
        ?string $version,
        ?string $sha256,
    ): array {
        return [
            'valid' => $valid,
            'total' => $total,
            'counts' => $counts,
            'expected' => $expected,
            'route_counts' => $routeCounts,
            'unique_operation_keys' => $uniqueKeys,
            'unique_coordinates' => $uniqueCoords,
            'errors' => $errors,
            'manifest_version' => $version,
            'sha256' => $sha256,
        ];
    }
}
