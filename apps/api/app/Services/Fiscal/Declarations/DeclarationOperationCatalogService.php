<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use RuntimeException;

/**
 * Projeção pública do recorte declarativo do catálogo oficial.
 * Coordenadas, operation_key e schemas permanecem estritamente internos.
 */
final class DeclarationOperationCatalogService
{
    public function __construct(
        private readonly OfficialServiceCatalogManifest $manifest,
        private readonly DeclarationOperationRegistry $registry,
    ) {}

    /**
     * @return array{
     *   verified_at: string,
     *   counts: array{total: int, production: int, prospection: int, read: int, mutation: int, production_read: int, production_mutation: int, executable: int},
     *   operations: list<array<string, mixed>>
     * }
     */
    public function publicCatalog(): array
    {
        $manifest = $this->manifest->load();
        $operations = [];

        foreach ($manifest['entries'] as $entry) {
            $system = (string) ($entry['id_sistema'] ?? '');
            if (! $this->registry->isDeclarationSystem($system)) {
                continue;
            }

            $operationKey = (string) ($entry['operation_key'] ?? '');
            $officialState = (string) ($entry['official_state'] ?? 'UNKNOWN');
            $support = (string) ($entry['platform_support'] ?? 'INVENTORIED');
            $isMutating = (bool) ($entry['is_mutating'] ?? true);
            $production = $officialState === SerproOfficialState::Production->value;
            $implemented = in_array($support, [
                SerproPlatformSupport::Implemented->value,
                SerproPlatformSupport::ProductionValidated->value,
            ], true);
            $executable = $production && $implemented;

            $operations[] = [
                'action_id' => $this->registry->actionIdFor($operationKey),
                'obligation' => $this->registry->obligationForSystem($system),
                'label' => (string) ($entry['label'] ?? ''),
                'official_route' => (string) ($entry['route'] ?? ''),
                'flow' => $isMutating ? 'MUTATION' : 'READ',
                'official_state' => $officialState,
                'implementation_state' => $support,
                'availability' => match (true) {
                    ! $production => 'PROSPECTION',
                    ! $implemented => 'NOT_IMPLEMENTED',
                    $isMutating => 'CONTROLLED',
                    default => 'AVAILABLE',
                },
                'executable' => $executable,
                'requires_preflight' => $isMutating && $production,
                'is_billable' => (string) ($entry['billable_class'] ?? '') !== 'NAO_BILHETAVEL',
                'async' => (string) ($entry['async_policy'] ?? 'HTTP_STATUS') !== 'HTTP_STATUS',
                'params' => $this->registry->publicParamsFor($operationKey),
                'result_kind' => $this->resultKind($entry),
            ];
        }

        $this->assertExactInventory($operations);

        return [
            'verified_at' => (string) ($manifest['verified_at'] ?? ''),
            'counts' => [
                'total' => count($operations),
                'production' => $this->count($operations, 'official_state', SerproOfficialState::Production->value),
                'prospection' => $this->count($operations, 'official_state', SerproOfficialState::Prospection->value),
                'read' => $this->count($operations, 'flow', 'READ'),
                'mutation' => $this->count($operations, 'flow', 'MUTATION'),
                'production_read' => $this->countWhere($operations, [
                    'official_state' => SerproOfficialState::Production->value,
                    'flow' => 'READ',
                ]),
                'production_mutation' => $this->countWhere($operations, [
                    'official_state' => SerproOfficialState::Production->value,
                    'flow' => 'MUTATION',
                ]),
                'executable' => count(array_filter(
                    $operations,
                    static fn (array $operation): bool => $operation['executable'] === true,
                )),
            ],
            'operations' => $operations,
        ];
    }

    /** @param array<string, mixed> $entry */
    private function resultKind(array $entry): string
    {
        $fields = $entry['response_schema']['fields'] ?? [];
        if (! is_array($fields)) {
            return 'STRUCTURED';
        }

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $haystack = strtolower((string) ($field['field'] ?? '').' '.(string) ($field['type'] ?? ''));
            if (str_contains($haystack, 'pdf') || str_contains($haystack, 'xml')) {
                return 'DOCUMENT';
            }
        }

        return 'STRUCTURED';
    }

    /** @param list<array<string, mixed>> $operations */
    private function count(array $operations, string $field, string $value): int
    {
        return count(array_filter(
            $operations,
            static fn (array $operation): bool => ($operation[$field] ?? null) === $value,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @param  array<string, string>  $expected
     */
    private function countWhere(array $operations, array $expected): int
    {
        return count(array_filter($operations, static function (array $operation) use ($expected): bool {
            foreach ($expected as $field => $value) {
                if (($operation[$field] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @param list<array<string, mixed>> $operations */
    private function assertExactInventory(array $operations): void
    {
        if (count($operations) !== count($this->registry->operationKeys())) {
            throw new RuntimeException('DECLARATION_CATALOG_DRIFT: inventário declarativo não corresponde à allowlist.');
        }

        $actionIds = array_column($operations, 'action_id');
        if (count($actionIds) !== count(array_unique($actionIds))) {
            throw new RuntimeException('DECLARATION_CATALOG_DRIFT: action_id duplicado.');
        }
    }
}
