<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use Illuminate\Support\Collection;

/**
 * Projeta a cobertura declarativa pública a partir do manifesto oficial local.
 *
 * Coordenadas, chaves internas, schemas e payloads nunca atravessam esta fronteira.
 */
final class DeclarationIntegrationCoverageService
{
    /** @var array<string, array{label: string, system: string, source_label: string}> */
    private const INTEGRA_OBLIGATIONS = [
        'PGDAS' => [
            'label' => 'PGDAS-D',
            'system' => 'PGDASD',
            'source_label' => 'Integra-SN · PGDAS-D',
        ],
        'DEFIS' => [
            'label' => 'DEFIS',
            'system' => 'DEFIS',
            'source_label' => 'Integra-SN · DEFIS',
        ],
        'DASN_SIMEI' => [
            'label' => 'DASN-SIMEI',
            'system' => 'DASNSIMEI',
            'source_label' => 'Integra-MEI · DASN-SIMEI',
        ],
        'DCTFWEB' => [
            'label' => 'DCTFWeb',
            'system' => 'DCTFWEB',
            'source_label' => 'Integra-DCTFWeb',
        ],
        'MIT' => [
            'label' => 'MIT',
            'system' => 'MIT',
            'source_label' => 'Integra-DCTFWeb · MIT',
        ],
    ];

    public function __construct(
        private readonly OfficialServiceCatalogManifest $manifest,
    ) {}

    /**
     * @return array{
     *   manifest_version: string,
     *   verified_at: string,
     *   truth_note: string,
     *   obligations: list<array<string, mixed>>
     * }
     */
    public function publicCoverage(): array
    {
        $manifest = $this->manifest->load();
        $entries = collect($manifest['entries']);

        $obligations = collect(self::INTEGRA_OBLIGATIONS)
            ->map(fn (array $definition, string $code): array => $this->integraCoverage(
                $code,
                $definition,
                $entries,
                (string) $manifest['verified_at'],
            ))
            ->values()
            ->all();

        $obligations[] = $this->externalCoverage(
            code: 'FGTS',
            label: 'FGTS Digital',
            sourceLabel: 'FGTS Digital · eSocial',
            coverage: 'PARTIAL',
            note: 'Cobertura externa ao Integra Contador; a central exibe somente evidências locais já observadas.',
            verifiedAt: (string) $manifest['verified_at'],
        );
        $obligations[] = $this->externalCoverage(
            code: 'DIRF',
            label: 'DIRF',
            sourceLabel: 'Obrigação histórica · fora do Integra Contador',
            coverage: 'UNSUPPORTED',
            note: 'Sem operação no catálogo Integra Contador; nenhum status fiscal é presumido.',
            verifiedAt: (string) $manifest['verified_at'],
        );

        return [
            'manifest_version' => (string) $manifest['manifest_version'],
            'verified_at' => (string) $manifest['verified_at'],
            'truth_note' => 'Catálogo oficial e implementação técnica não equivalem a validação fiscal produtiva; mutações continuam sujeitas aos gates fail-closed.',
            'obligations' => $obligations,
        ];
    }

    /**
     * @param  array{label: string, system: string, source_label: string}  $definition
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function integraCoverage(
        string $code,
        array $definition,
        Collection $entries,
        string $verifiedAt,
    ): array {
        $operations = $entries
            ->where('id_sistema', $definition['system'])
            ->values();
        $implemented = $operations->filter(fn (array $entry): bool => $this->isImplemented($entry));
        $inventoried = $operations->where('platform_support', SerproPlatformSupport::Inventoried->value);
        $monitoringSupported = $implemented->contains(
            fn (array $entry): bool => ($entry['is_mutating'] ?? true) === false,
        );
        $transmissionSupported = $implemented->contains(
            fn (array $entry): bool => ($entry['route'] ?? null) === 'Declarar',
        );

        $coverage = match (true) {
            $operations->isEmpty() => 'UNSUPPORTED',
            $implemented->isEmpty() && $inventoried->isNotEmpty() => 'INVENTORIED',
            $implemented->count() === $operations->count() => 'FULL',
            default => 'PARTIAL',
        };

        return [
            'code' => $code,
            'label' => $definition['label'],
            'source_kind' => 'INTEGRA_CONTADOR',
            'source_label' => $definition['source_label'],
            'coverage' => $coverage,
            'monitoring_supported' => $monitoringSupported,
            'transmission_supported' => $transmissionSupported,
            'operations_total' => $operations->count(),
            'implemented_operations' => $implemented->count(),
            'inventoried_operations' => $inventoried->count(),
            'routes' => $operations
                ->pluck('route')
                ->filter(fn (mixed $route): bool => is_string($route) && $route !== '')
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'verified_at' => $verifiedAt,
            'note' => $coverage === 'INVENTORIED'
                ? 'Serviço oficial catalogado, porém ainda não executável pelo hub.'
                : 'Cobertura técnica do hub; consultas e mutações continuam sujeitas a autorização e gates operacionais.',
        ];
    }

    /** @param array<string, mixed> $entry */
    private function isImplemented(array $entry): bool
    {
        $support = SerproPlatformSupport::tryFrom((string) ($entry['platform_support'] ?? ''));

        return in_array($support, [
            SerproPlatformSupport::Implemented,
            SerproPlatformSupport::ProductionValidated,
        ], true)
            && ($entry['official_state'] ?? null) === SerproOfficialState::Production->value;
    }

    /** @return array<string, mixed> */
    private function externalCoverage(
        string $code,
        string $label,
        string $sourceLabel,
        string $coverage,
        string $note,
        string $verifiedAt,
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'source_kind' => 'EXTERNAL',
            'source_label' => $sourceLabel,
            'coverage' => $coverage,
            'monitoring_supported' => false,
            'transmission_supported' => false,
            'operations_total' => 0,
            'implemented_operations' => 0,
            'inventoried_operations' => 0,
            'routes' => [],
            'verified_at' => $verifiedAt,
            'note' => $note,
        ];
    }
}
