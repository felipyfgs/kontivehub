<?php

namespace App\Services\Serpro\Catalog;

use App\Models\SerproServiceCatalogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importa o manifesto versionado para a projeção consultável.
 * Nova versão efetiva encerra a anterior sem apagar histórico.
 */
final class OfficialServiceCatalogImporter
{
    public function __construct(
        private readonly OfficialServiceCatalogManifest $manifest,
    ) {}

    /**
     * @return array{
     *   valid: bool,
     *   imported: int,
     *   updated: int,
     *   skipped: int,
     *   closed: int,
     *   errors: list<string>,
     *   manifest_version: string|null,
     *   catalog_version: int|null,
     *   validation: array<string, mixed>
     * }
     */
    public function import(?string $absolutePath = null, ?int $catalogVersion = null): array
    {
        $validation = $this->manifest->validate(null, $absolutePath);
        if (! $validation['valid']) {
            return [
                'valid' => false,
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'closed' => 0,
                'errors' => $validation['errors'],
                'manifest_version' => $validation['manifest_version'],
                'catalog_version' => null,
                'validation' => $validation,
            ];
        }

        $data = $this->manifest->load($absolutePath);
        // Sempre nova revisão (suporta >1 revisão/dia); snapshots anteriores permanecem imutáveis.
        $version = $catalogVersion ?? $this->nextCatalogRevision($data['manifest_version']);
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $closed = 0;

        DB::transaction(function () use ($data, $version, &$imported, &$updated, &$skipped, &$closed): void {
            $now = now();

            foreach ($data['entries'] as $entry) {
                $payload = $this->mapEntry($entry, $version, $data, $now);

                // Snapshots imutáveis: nunca UPDATE de linha histórica; só insert + close.
                $active = SerproServiceCatalogEntry::query()
                    ->where('operation_key', $entry['operation_key'])
                    ->whereNull('effective_to')
                    ->orderByDesc('catalog_version')
                    ->first();

                if ($active !== null && $this->snapshotEquals($active, $payload)) {
                    $skipped++;
                    $this->upsertCanonical($active, $entry, $data, $now);

                    continue;
                }

                // Encerra versão ativa anterior (sem reescrever payload)
                if ($active !== null) {
                    $active->forceFill(['effective_to' => $now])->save();
                    $closed++;
                }

                $existing = SerproServiceCatalogEntry::query()->create($payload);
                $imported++;

                $this->upsertCanonical($existing, $entry, $data, $now);
            }
        });

        return [
            'valid' => true,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'closed' => $closed,
            'errors' => [],
            'manifest_version' => $data['manifest_version'],
            'catalog_version' => $version,
            'validation' => $validation,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function mapEntry(array $entry, int $version, array $manifest, mixed $now): array
    {
        $hasNewColumns = Schema::hasColumn('serpro_service_catalog_entries', 'operation_key');
        $isProduction = ($entry['official_state'] ?? '') === 'PRODUCTION';
        $support = (string) ($entry['platform_support'] ?? 'INVENTORIED');

        $metadata = [
            'manifest_version' => $manifest['manifest_version'],
            'source' => $manifest['source'],
            'verified_at' => $manifest['verified_at'],
            'source_snapshots' => $manifest['source_snapshots'] ?? [],
            'dados_mode' => $entry['dados_mode'],
            'route' => $entry['route'],
            'versao_sistema' => $entry['versao_sistema'],
            'auth_mode' => $entry['auth_mode'] ?? null,
            'proxy_rule' => $entry['proxy_rule'] ?? null,
            'required_proxy_powers' => $entry['required_proxy_powers'] ?? [],
            'async_policy' => $entry['async_policy'] ?? null,
            'monitoring_module' => $entry['monitoring_module'] ?? null,
            'request_schema' => $entry['request_schema'] ?? null,
            'response_schema' => $entry['response_schema'] ?? null,
            'sources' => $entry['sources'] ?? [],
            'catalog_code' => $entry['catalog_code'] ?? null,
            'sequence' => $entry['sequence'] ?? null,
        ];

        $base = [
            'catalog_version' => $version,
            'environment' => 'PRODUCTION',
            'solution_code' => $entry['id_sistema'],
            'service_code' => $entry['id_sistema'],
            'operation_code' => $entry['id_servico'],
            'label' => $entry['label'],
            'is_mutating' => (bool) $entry['is_mutating'],
            'is_enabled' => $isProduction && $support !== 'INVENTORIED',
            'required_proxy_power' => $entry['required_proxy_power'],
            'billable_class' => $entry['billable_class'],
            'cache_ttl_seconds' => $entry['id_sistema'] === 'SITFIS' ? 86400 : 3600,
            // O catálogo oficial não publica um limite universal. Configuração
            // contratual por operação é opt-in; null evita número inventado.
            'rate_limit_per_minute' => null,
            'coverage' => $support === 'INVENTORIED' ? 'INVENTORIED' : 'KNOWN',
            'metadata' => $metadata,
            'effective_from' => CarbonImmutable::parse((string) $manifest['verified_at'])->startOfDay(),
            'effective_to' => null,
        ];

        if ($hasNewColumns) {
            $base['operation_key'] = $entry['operation_key'];
            $base['id_sistema'] = $entry['id_sistema'];
            $base['id_servico'] = $entry['id_servico'];
            $base['versao_sistema'] = $entry['versao_sistema'];
            $base['functional_route'] = $entry['route'];
            $base['official_state'] = $entry['official_state'];
            $base['platform_support'] = $support;
            $base['dados_mode'] = $entry['dados_mode'];
        }

        return $base;
    }

    /**
     * Atualiza catálogo canônico (serpro_operations + versions) sem apagar histórico.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $manifest
     */
    private function upsertCanonical(
        SerproServiceCatalogEntry $catalogEntry,
        array $entry,
        array $manifest,
        mixed $now,
    ): void {
        if (! Schema::hasTable('serpro_operations') || ! Schema::hasTable('serpro_operation_versions')) {
            return;
        }

        $key = (string) $entry['operation_key'];
        $opId = DB::table('serpro_operations')->where('operation_key', $key)->value('id');
        $sanitizedMeta = [
            'auth_mode' => $entry['auth_mode'] ?? null,
            'proxy_rule' => $entry['proxy_rule'] ?? null,
            'async_policy' => $entry['async_policy'] ?? null,
            'monitoring_module' => $entry['monitoring_module'] ?? null,
            'manifest_version' => $manifest['manifest_version'] ?? null,
            'billable_class' => $entry['billable_class'] ?? null,
            'official_state' => $entry['official_state'] ?? null,
            'platform_support' => $entry['platform_support'] ?? null,
        ];

        $isEnabled = ($entry['official_state'] ?? '') === 'PRODUCTION'
            && in_array($entry['platform_support'] ?? '', ['IMPLEMENTED', 'PRODUCTION_VALIDATED'], true);

        if ($opId === null) {
            $opId = DB::table('serpro_operations')->insertGetId([
                'operation_key' => $key,
                'label' => $entry['label'] ?? $key,
                'is_enabled' => $isEnabled,
                'consumption_class' => $entry['billable_class'] ?? null,
                'metadata_sanitized' => json_encode($sanitizedMeta, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('serpro_operations')->where('id', $opId)->update([
                'label' => $entry['label'] ?? $key,
                'is_enabled' => $isEnabled,
                'consumption_class' => $entry['billable_class'] ?? null,
                'metadata_sanitized' => json_encode($sanitizedMeta, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
        }

        $coords = [
            'id_sistema' => (string) $entry['id_sistema'],
            'id_servico' => (string) $entry['id_servico'],
            'versao_sistema' => (string) $entry['versao_sistema'],
            'functional_route' => (string) $entry['route'],
        ];

        $active = DB::table('serpro_operation_versions')
            ->where('serpro_operation_id', $opId)
            ->whereNull('effective_to')
            ->orderByDesc('id')
            ->first();

        $sourceRowId = (int) $catalogEntry->getKey();
        $same = $active !== null
            && (string) ($active->id_sistema ?? $active->system_code ?? '') === $coords['id_sistema']
            && (string) ($active->id_servico ?? $active->service_code ?? '') === $coords['id_servico']
            && (string) ($active->versao_sistema ?? '') === $coords['versao_sistema']
            && (string) ($active->functional_route ?? '') === $coords['functional_route']
            && (string) ($active->source_catalog ?? '') === 'official_manifest'
            && (int) ($active->source_row_id ?? 0) === $sourceRowId;

        // Versões imutáveis: se coordenadas e metadados oficiais idênticos, não reescreve.
        if ($same) {
            return;
        }

        if ($active !== null) {
            DB::table('serpro_operation_versions')
                ->where('id', $active->id)
                ->update(['effective_to' => $now, 'updated_at' => $now]);
        }

        DB::table('serpro_operation_versions')->insert([
            'serpro_operation_id' => $opId,
            'system_code' => $coords['id_sistema'],
            'service_code' => $coords['id_servico'],
            'operation_code' => $coords['id_servico'],
            'id_sistema' => $coords['id_sistema'],
            'id_servico' => $coords['id_servico'],
            'versao_sistema' => $coords['versao_sistema'],
            'functional_route' => $coords['functional_route'],
            'effective_from' => $now,
            'effective_to' => null,
            'source_catalog' => 'official_manifest',
            'source_row_id' => $sourceRowId,
            'auth_mode' => $entry['auth_mode'],
            'proxy_rule' => $entry['proxy_rule'],
            'required_proxy_powers' => json_encode($entry['required_proxy_powers'], JSON_THROW_ON_ERROR),
            'official_state' => $entry['official_state'],
            'platform_support' => $entry['platform_support'],
            'monitoring_module' => $entry['monitoring_module'],
            'is_mutating' => (bool) $entry['is_mutating'],
            'billable_class' => $entry['billable_class'],
            'dados_mode' => $entry['dados_mode'],
            'async_policy' => $entry['async_policy'],
            'request_schema' => json_encode($entry['request_schema'], JSON_THROW_ON_ERROR),
            'response_schema' => json_encode($entry['response_schema'], JSON_THROW_ON_ERROR),
            'source_evidence' => json_encode($entry['sources'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Próxima revisão monotônica — permite múltiplas importações no mesmo dia.
     * Snapshots anteriores nunca são sobrescritos.
     */
    private function nextCatalogRevision(string $manifestVersion): int
    {
        $digits = preg_replace('/\D+/', '', $manifestVersion) ?: '1';
        $base = (int) substr($digits, 0, 8) ?: 1;

        $max = (int) SerproServiceCatalogEntry::query()->max('catalog_version');
        if ($max <= 0) {
            return $base;
        }

        // Sempre avança: multi-revisão/dia e imutabilidade do snapshot anterior.
        return max($max + 1, $base);
    }

    /**
     * Compara snapshot ativo com payload candidata sem considerar timestamps/versão.
     *
     * @param  array<string, mixed>  $payload
     */
    private function snapshotEquals(SerproServiceCatalogEntry $active, array $payload): bool
    {
        $keys = [
            'operation_key',
            'id_sistema',
            'id_servico',
            'versao_sistema',
            'functional_route',
            'solution_code',
            'service_code',
            'operation_code',
            'label',
            'is_mutating',
            'is_enabled',
            'required_proxy_power',
            'billable_class',
            'platform_support',
            'official_state',
            'dados_mode',
        ];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $left = $active->{$key} ?? null;
            $right = $payload[$key];
            if ($left instanceof \BackedEnum) {
                $left = $left->value;
            }
            if ($right instanceof \BackedEnum) {
                $right = $right->value;
            }
            if ((string) $left !== (string) $right) {
                return false;
            }
        }

        return true;
    }

    /** @deprecated use nextCatalogRevision */
    private function resolveCatalogVersion(string $manifestVersion): int
    {
        return $this->nextCatalogRevision($manifestVersion);
    }
}
