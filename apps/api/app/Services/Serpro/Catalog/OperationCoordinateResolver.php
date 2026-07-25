<?php

namespace App\Services\Serpro\Catalog;

use App\Enums\SerproFunctionalRoute;
use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use App\Models\SerproServiceCatalogEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolve operation_key → coordenadas SERPRO oficiais (fonte: projeção DB ou manifesto).
 * Jobs entregam operation_key; nunca montam idSistema/idServico a partir do frontend.
 */
final class OperationCoordinateResolver
{
    public function __construct(
        private readonly OfficialServiceCatalogManifest $manifest,
    ) {}

    /**
     * @return array{
     *   operation_key: string,
     *   id_sistema: string,
     *   id_servico: string,
     *   versao_sistema: string,
     *   route: SerproFunctionalRoute,
     *   required_proxy_power: string|null,
     *   required_proxy_powers: list<string>,
     *   platform_support: SerproPlatformSupport,
     *   official_state: SerproOfficialState|null,
     *   auth_mode: string,
     *   proxy_rule: string,
     *   async_policy: string,
     *   monitoring_module: string|null,
     *   dados_mode: string,
     *   billable_class: string,
     *   is_mutating: bool,
     *   label: string
     * }
     */
    public function resolve(string $operationKey): array
    {
        $production = $this->isProductionRuntime();
        $dbFailed = false;

        try {
            $row = SerproServiceCatalogEntry::query()
                ->where('operation_key', $operationKey)
                ->whereNull('effective_to')
                ->orderByDesc('catalog_version')
                ->first();
        } catch (\Throwable $e) {
            $dbFailed = true;
            $row = null;
            if ($production) {
                throw new RuntimeException(
                    'CATALOG_SOURCE_UNAVAILABLE: falha ao ler catálogo em produção ('.$e->getMessage().').'
                );
            }
        }

        if ($row !== null && $row->operation_key) {
            return $this->mapCatalogRow($row);
        }

        // Autoridade canônica serpro_operations + versions (plano de controle)
        try {
            $canonical = $this->resolveFromCanonicalOperations($operationKey);
        } catch (\Throwable $e) {
            if ($production) {
                throw new RuntimeException(
                    'CATALOG_SOURCE_UNAVAILABLE: falha no catálogo canônico em produção ('.$e->getMessage().').'
                );
            }
            $canonical = null;
        }
        if ($canonical !== null) {
            return $canonical;
        }

        // Produção: sem fallback inventado quando a fonte DB existe mas a chave falta
        // (ou falhou). Manifesto só em non-prod / bootstrap sem projeção.
        if ($production && ! $dbFailed && $this->hasCatalogProjection()) {
            throw new RuntimeException(
                'CAPABILITY_NOT_FOUND: operation_key ausente no catálogo projetado ('.$operationKey.').'
            );
        }

        // Manifesto versionado (fonte offline oficial) — não inventa defaults
        try {
            $m = $this->manifest->load();
            $entry = $this->manifest->findByOperationKey($m, $operationKey);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'CATALOG_SOURCE_UNAVAILABLE: manifesto/fonte indisponível ('.$e->getMessage().').',
                0,
                $e
            );
        }

        $support = SerproPlatformSupport::from($entry['platform_support']);
        $route = SerproFunctionalRoute::from($entry['route']);
        $official = SerproOfficialState::tryFrom((string) $entry['official_state']);

        return [
            'operation_key' => $entry['operation_key'],
            'id_sistema' => $entry['id_sistema'],
            'id_servico' => $entry['id_servico'],
            'versao_sistema' => $entry['versao_sistema'],
            'route' => $route,
            'required_proxy_power' => $entry['required_proxy_power'],
            'required_proxy_powers' => $this->normalizePowers(
                $entry['required_proxy_powers'] ?? $entry['required_proxy_power']
            ),
            'platform_support' => $support,
            'official_state' => $official,
            'auth_mode' => (string) ($entry['auth_mode'] ?? 'PROCURATOR_WHEN_REPRESENTING'),
            'proxy_rule' => (string) ($entry['proxy_rule'] ?? 'NOT_APPLICABLE'),
            'async_policy' => (string) ($entry['async_policy'] ?? 'HTTP_STATUS'),
            'monitoring_module' => isset($entry['monitoring_module']) ? (string) $entry['monitoring_module'] : null,
            'dados_mode' => $entry['dados_mode'],
            'billable_class' => $entry['billable_class'],
            'is_mutating' => (bool) $entry['is_mutating'],
            'label' => $entry['label'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCatalogRow(SerproServiceCatalogEntry $row): array
    {
        $support = $row->platform_support instanceof SerproPlatformSupport
            ? $row->platform_support
            : SerproPlatformSupport::tryFrom((string) $row->platform_support);
        if ($support === null) {
            throw new RuntimeException(
                'CATALOG_COORDINATE_INVALID: platform_support ausente/ inválido para '.$row->operation_key
            );
        }

        $routeRaw = $row->functional_route instanceof SerproFunctionalRoute
            ? $row->functional_route
            : SerproFunctionalRoute::tryFrom((string) ($row->functional_route ?? ''));
        if ($routeRaw === null) {
            throw new RuntimeException(
                'CATALOG_COORDINATE_INVALID: functional_route ausente/inválida para '.$row->operation_key
            );
        }

        $official = $row->official_state instanceof SerproOfficialState
            ? $row->official_state
            : SerproOfficialState::tryFrom((string) ($row->official_state ?? ''));
        $meta = is_array($row->metadata) ? $row->metadata : [];

        $idSistema = (string) ($row->id_sistema ?: $row->solution_code);
        $idServico = (string) ($row->id_servico ?: $row->operation_code);
        if ($idSistema === '' || $idServico === '') {
            throw new RuntimeException(
                'CATALOG_COORDINATE_INVALID: id_sistema/id_servico ausentes para '.$row->operation_key
            );
        }

        return [
            'operation_key' => (string) $row->operation_key,
            'id_sistema' => $idSistema,
            'id_servico' => $idServico,
            'versao_sistema' => (string) ($row->versao_sistema ?: '1.0'),
            'route' => $routeRaw,
            'required_proxy_power' => $row->required_proxy_power,
            'required_proxy_powers' => $this->normalizePowers(
                $meta['required_proxy_powers'] ?? $row->required_proxy_power
            ),
            'platform_support' => $support,
            'official_state' => $official,
            'auth_mode' => (string) ($meta['auth_mode'] ?? 'PROCURATOR_WHEN_REPRESENTING'),
            'proxy_rule' => (string) ($meta['proxy_rule'] ?? 'NOT_APPLICABLE'),
            'async_policy' => (string) ($meta['async_policy'] ?? 'HTTP_STATUS'),
            'monitoring_module' => isset($meta['monitoring_module']) ? (string) $meta['monitoring_module'] : null,
            'dados_mode' => (string) ($row->dados_mode ?: 'JSON_STRING'),
            'billable_class' => $row->billable_class?->value ?? 'DESCONHECIDA',
            'is_mutating' => (bool) $row->is_mutating,
            'label' => (string) $row->label,
        ];
    }

    private function isProductionRuntime(): bool
    {
        return app()->environment('production')
            || (bool) config('serpro.fail_closed_catalog', false);
    }

    private function hasCatalogProjection(): bool
    {
        try {
            return SerproServiceCatalogEntry::query()->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveExecutable(string $operationKey): array
    {
        $coords = $this->resolve($operationKey);

        $state = $coords['official_state'] ?? null;
        if ($state instanceof SerproOfficialState && $state !== SerproOfficialState::Production) {
            throw new RuntimeException(
                'CAPABILITY_NOT_EXECUTABLE: operação não produtiva ('.$operationKey.', '.$state->value.').'
            );
        }

        if (! $coords['platform_support']->isExecutable()) {
            throw new RuntimeException(
                'CAPABILITY_NOT_IMPLEMENTED: operação inventariada sem adapter ('.$operationKey.').'
            );
        }

        return $coords;
    }

    /**
     * @return list<string>
     */
    private function normalizePowers(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                static fn ($p): string => trim((string) $p),
                $raw,
            ), static fn (string $p): bool => $p !== ''));
        }
        if (is_string($raw) && trim($raw) !== '') {
            return preg_split('/[\s,]+/', trim($raw)) ?: [];
        }

        return [];
    }

    /**
     * @return array{
     *   operation_key: string,
     *   id_sistema: string,
     *   id_servico: string,
     *   versao_sistema: string,
     *   route: SerproFunctionalRoute,
     *   required_proxy_power: string|null,
     *   required_proxy_powers: list<string>,
     *   platform_support: SerproPlatformSupport,
     *   official_state: SerproOfficialState|null,
     *   auth_mode: string,
     *   proxy_rule: string,
     *   async_policy: string,
     *   monitoring_module: string|null,
     *   dados_mode: string,
     *   billable_class: string,
     *   is_mutating: bool,
     *   label: string
     * }|null
     */
    private function resolveFromCanonicalOperations(string $operationKey): ?array
    {
        try {
            $op = DB::table('serpro_operations')
                ->where('operation_key', $operationKey)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if ($op === null) {
            return null;
        }

        try {
            $version = DB::table('serpro_operation_versions')
                ->where('serpro_operation_id', $op->id)
                ->whereNull('effective_to')
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            $version = null;
        }

        // Sem versão efetiva não há coordenadas executáveis — cair no manifesto.
        if ($version === null) {
            return null;
        }

        $route = SerproFunctionalRoute::tryFrom((string) ($version->functional_route ?? ''));
        if ($route === null) {
            // Sem default inventado (ex.: Consultar) — coordenadas inválidas
            return null;
        }

        // Preferir flags da versão/metadata quando existirem; desconhecido → fail-closed (mutante).
        $isMutating = filter_var($version->is_mutating ?? $op->is_mutating ?? true, FILTER_VALIDATE_BOOL);
        $proxyPower = $version->required_proxy_power ?? $op->required_proxy_power ?? null;
        $supportRaw = (string) ($version->platform_support ?? $op->platform_support ?? '');
        $platformSupport = SerproPlatformSupport::tryFrom($supportRaw);
        if ($platformSupport === null) {
            return null;
        }

        /** @var array<string, mixed> $meta */
        $meta = [];
        if (isset($op->metadata_sanitized) && is_string($op->metadata_sanitized) && $op->metadata_sanitized !== '') {
            $decoded = json_decode($op->metadata_sanitized, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $official = SerproOfficialState::tryFrom((string) ($meta['official_state'] ?? ''));

        return [
            'operation_key' => (string) $op->operation_key,
            'id_sistema' => (string) ($version->id_sistema ?? $version->system_code ?? ''),
            'id_servico' => (string) ($version->id_servico ?? $version->service_code ?? ''),
            'versao_sistema' => (string) ($version->versao_sistema ?? '1.0'),
            'route' => $route,
            'required_proxy_power' => $proxyPower !== null ? (string) $proxyPower : null,
            'required_proxy_powers' => $this->normalizePowers($proxyPower),
            'platform_support' => $platformSupport,
            'official_state' => $official,
            'auth_mode' => (string) ($meta['auth_mode'] ?? 'PROCURATOR_WHEN_REPRESENTING'),
            'proxy_rule' => (string) ($meta['proxy_rule'] ?? 'NOT_APPLICABLE'),
            'async_policy' => (string) ($meta['async_policy'] ?? 'HTTP_STATUS'),
            'monitoring_module' => isset($meta['monitoring_module']) ? (string) $meta['monitoring_module'] : null,
            'dados_mode' => (string) ($version->dados_mode ?? 'JSON_STRING'),
            'billable_class' => (string) ($op->consumption_class ?? 'DESCONHECIDA'),
            'is_mutating' => $isMutating,
            'label' => (string) ($op->label ?? $op->operation_key),
        ];
    }
}
