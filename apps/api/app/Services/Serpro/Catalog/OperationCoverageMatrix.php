<?php

namespace App\Services\Serpro\Catalog;

use App\Enums\SerproPlatformSupport;
use App\Models\SerproServiceCatalogEntry;
use App\Services\Serpro\CapabilityDriverResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz de coverage por operation_key.
 *
 * IMPLEMENTED somente com: fonte, coordenadas, auth, poder, cobrança, codec,
 * driver, fixture e testes. Ausência de qualquer item → CATALOGUED/INVENTORIED.
 */
final class OperationCoverageMatrix
{
    /**
     * Dimensões obrigatórias para classificar como IMPLEMENTED.
     *
     * @var list<string>
     */
    public const REQUIRED_FOR_IMPLEMENTED = [
        'source',
        'coordinates',
        'auth',
        'power',
        'billing',
        'codec',
        'driver',
        'fixture',
        'tests',
    ];

    public function __construct(
        private readonly OfficialServiceCatalogManifest $manifest,
        private readonly OperationCoordinateResolver $coordinates,
    ) {}

    /**
     * @return array{
     *   operation_key: string,
     *   platform_support: string,
     *   declared_implemented: bool,
     *   eligible_implemented: bool,
     *   dimensions: array<string, bool>,
     *   missing: list<string>,
     *   reasons: list<string>
     * }
     */
    public function evaluate(string $operationKey): array
    {
        $dimensions = array_fill_keys(self::REQUIRED_FOR_IMPLEMENTED, false);
        $reasons = [];

        try {
            $coords = $this->coordinates->resolve($operationKey);
            $dimensions['coordinates'] = ($coords['id_sistema'] ?? '') !== ''
                && ($coords['id_servico'] ?? '') !== '';
            $dimensions['auth'] = ($coords['auth_mode'] ?? '') !== '';
            $dimensions['power'] = true; // poder pode ser NOT_APPLICABLE (lista vazia ok)
            $dimensions['billing'] = ($coords['billable_class'] ?? '') !== ''
                && strtoupper((string) $coords['billable_class']) !== 'DESCONHECIDA';
            $support = $coords['platform_support'] ?? null;
            $declared = $support instanceof SerproPlatformSupport
                && in_array($support, [
                    SerproPlatformSupport::Implemented,
                    SerproPlatformSupport::ProductionValidated,
                ], true);
        } catch (\Throwable $e) {
            $reasons[] = 'coordinates_unresolvable: '.$e->getMessage();
            $declared = false;
            $coords = null;
        }

        $entry = $this->manifestEntry($operationKey);
        if ($entry !== null) {
            $sources = $entry['sources'] ?? [];
            $dimensions['source'] = is_array($sources) && $sources !== [];
            $hasRequest = is_array($entry['request_schema'] ?? null);
            $hasResponse = is_array($entry['response_schema'] ?? null);
            $dimensions['codec'] = $hasRequest && $hasResponse;
            $dimensions['auth'] = $dimensions['auth'] || (($entry['auth_mode'] ?? '') !== '');
            $dimensions['billing'] = $dimensions['billing']
                || (($entry['billable_class'] ?? '') !== '' && $entry['billable_class'] !== 'DESCONHECIDA');
        } else {
            $reasons[] = 'manifest_entry_missing';
        }

        $dimensions['driver'] = $this->hasDriverBinding($operationKey);
        $dimensions['fixture'] = $this->hasFixture($operationKey);
        $dimensions['tests'] = $this->hasTests($operationKey);

        if (! $dimensions['source']) {
            $reasons[] = 'source_missing';
        }
        if (! $dimensions['codec']) {
            $reasons[] = 'codec_missing';
        }
        if (! $dimensions['driver']) {
            $reasons[] = 'driver_missing';
        }
        if (! $dimensions['fixture']) {
            $reasons[] = 'fixture_missing';
        }
        if (! $dimensions['tests']) {
            $reasons[] = 'tests_missing';
        }

        $missing = [];
        foreach (self::REQUIRED_FOR_IMPLEMENTED as $dim) {
            if (! ($dimensions[$dim] ?? false)) {
                $missing[] = $dim;
            }
        }

        $eligible = $missing === [];

        // Reclassificação: só IMPLEMENTED se elegível; senão permanece inventariado.
        $effectiveSupport = $eligible
            ? SerproPlatformSupport::Implemented->value
            : SerproPlatformSupport::Inventoried->value;

        if ($declared && ! $eligible) {
            $reasons[] = 'downgraded_from_declared_implemented';
            $effectiveSupport = SerproPlatformSupport::Inventoried->value;
        }

        return [
            'operation_key' => $operationKey,
            'platform_support' => $effectiveSupport,
            'declared_implemented' => $declared ?? false,
            'eligible_implemented' => $eligible,
            'dimensions' => $dimensions,
            'missing' => $missing,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function evaluateAllFromManifest(): array
    {
        $m = $this->manifest->load();
        $out = [];
        foreach ($m['entries'] as $entry) {
            $out[] = $this->evaluate((string) $entry['operation_key']);
        }

        return $out;
    }

    /**
     * Contagens agregadas (sem PII).
     *
     * @return array{total: int, implemented_eligible: int, inventoried: int, declared_but_incomplete: int}
     */
    public function summary(): array
    {
        $rows = $this->evaluateAllFromManifest();
        $implemented = 0;
        $declaredIncomplete = 0;
        foreach ($rows as $row) {
            if ($row['eligible_implemented']) {
                $implemented++;
            }
            if ($row['declared_implemented'] && ! $row['eligible_implemented']) {
                $declaredIncomplete++;
            }
        }

        return [
            'total' => count($rows),
            'implemented_eligible' => $implemented,
            'inventoried' => count($rows) - $implemented,
            'declared_but_incomplete' => $declaredIncomplete,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifestEntry(string $operationKey): ?array
    {
        try {
            $m = $this->manifest->load();

            return $this->manifest->findByOperationKey($m, $operationKey);
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasDriverBinding(string $operationKey): bool
    {
        // Capacidade mapeada no resolver implica driver configurável.
        $capability = app(CapabilityDriverResolver::class)->capabilityForOperationKey($operationKey);

        return $capability !== 'default' || config('serpro.capabilities.default') !== null;
    }

    private function hasFixture(string $operationKey): bool
    {
        $path = base_path('resources/serpro/contract-fixtures.v2026-07-16.json');
        if (! is_file($path)) {
            return false;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return false;
        }

        return str_contains($raw, $operationKey);
    }

    private function hasTests(string $operationKey): bool
    {
        // Heurística: presence em fixtures oficiais de contrato ou tests Unit/Serpro.
        $roots = [
            base_path('tests/Unit/Serpro'),
            base_path('tests/Feature/Serpro'),
            base_path('resources/serpro'),
        ];
        foreach ($roots as $root) {
            if (! is_dir($root) && ! is_file($root)) {
                continue;
            }
            $iterator = is_file($root)
                ? [new \SplFileInfo($root)]
                : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                if (! str_ends_with($file->getFilename(), '.php')
                    && ! str_ends_with($file->getFilename(), '.json')
                ) {
                    continue;
                }
                $content = @file_get_contents($file->getPathname());
                if (is_string($content) && str_contains($content, $operationKey)) {
                    return true;
                }
            }
        }

        // DB catalog metadata may declare test_ref
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            $row = SerproServiceCatalogEntry::query()
                ->where('operation_key', $operationKey)
                ->whereNull('effective_to')
                ->orderByDesc('catalog_version')
                ->first();
            if ($row !== null) {
                $meta = is_array($row->metadata) ? $row->metadata : [];
                if (! empty($meta['test_ref']) || ! empty($meta['has_tests'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
