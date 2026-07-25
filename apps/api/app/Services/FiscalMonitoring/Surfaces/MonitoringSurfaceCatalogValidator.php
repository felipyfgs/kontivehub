<?php

namespace App\Services\FiscalMonitoring\Surfaces;

use App\Enums\FiscalOperationClass;
use App\Enums\MonitoringChannel;
use App\Enums\MonitoringDocumentPolicy;
use App\Enums\MonitoringOfficialStateSummary;
use App\Enums\MonitoringResultKind;
use App\Enums\SerproOfficialState;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use InvalidArgumentException;
use RuntimeException;

/**
 * Valida contratos de superfície contra o catálogo oficial versionado (fail-closed).
 */
final class MonitoringSurfaceCatalogValidator
{
    public function __construct(
        private readonly OfficialServiceCatalogManifest $catalog,
    ) {}

    /**
     * @param  list<MonitoringSurfaceContract>|array<string, MonitoringSurfaceContract>  $contracts
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(array $contracts, ?array $manifest = null): array
    {
        $manifest ??= $this->catalog->load();
        $index = $this->indexByOperationKey($manifest);
        $errors = [];

        foreach ($contracts as $contract) {
            if (! $contract instanceof MonitoringSurfaceContract) {
                $errors[] = 'contrato inválido (tipo inesperado)';

                continue;
            }

            $errors = array_merge($errors, $this->validateContract($contract, $index));
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<MonitoringSurfaceContract>|array<string, MonitoringSurfaceContract>  $contracts
     *
     * @throws RuntimeException
     */
    public function assertValid(array $contracts, ?array $manifest = null): void
    {
        $result = $this->validate($contracts, $manifest);
        if (! $result['valid']) {
            throw new RuntimeException(
                'Contratos de superfície inválidos: '.implode('; ', $result['errors'])
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $index
     * @return list<string>
     */
    private function validateContract(MonitoringSurfaceContract $contract, array $index): array
    {
        $errors = [];
        $key = $contract->surfaceKey;

        if ($contract->surfaceKey === '' || $contract->routePattern === '') {
            $errors[] = "{$key}: surface_key/route_pattern obrigatórios";
        }

        if ($contract->allowsDocument && $contract->documentPolicy === MonitoringDocumentPolicy::Never) {
            $errors[] = "{$key}: allows_document=true incompatível com document_policy=NEVER";
        }

        if (! $contract->allowsDocument
            && in_array($contract->documentPolicy, [
                MonitoringDocumentPolicy::WhenArtifact,
                MonitoringDocumentPolicy::AsyncWhenReady,
            ], true)
        ) {
            $errors[] = "{$key}: document_policy documentável exige allows_document=true";
        }

        if ($contract->resultKind === MonitoringResultKind::Unavailable
            && $contract->allowsDocument
        ) {
            $errors[] = "{$key}: superfície UNAVAILABLE não pode permitir documento";
        }

        if ($contract->channel === MonitoringChannel::Aggregate
            && $contract->operationKeys !== []
            && $contract->resultKind === MonitoringResultKind::Aggregate
            && $contract->officialState !== MonitoringOfficialStateSummary::NotApplicable
            && $contract->surfaceKey === 'monitoring_dashboard'
        ) {
            // dashboard deve ser puro agregado sem ops
            $errors[] = "{$key}: dashboard agregado não deve declarar operation_keys";
        }

        $states = [];
        foreach ($contract->operationKeys as $opKey) {
            if ($opKey === '' || ! is_string($opKey)) {
                $errors[] = "{$key}: operation_key vazia";

                continue;
            }

            if (! isset($index[$opKey])) {
                $errors[] = "{$key}: operation_key ausente no catálogo: {$opKey}";

                continue;
            }

            $entry = $index[$opKey];
            $state = (string) ($entry['official_state'] ?? '');
            $states[] = $state;

            if ($contract->channel === MonitoringChannel::Integra
                && $contract->resultKind !== MonitoringResultKind::Unavailable
                && $state !== SerproOfficialState::Production->value
            ) {
                // Ops não produtivas em superfície produtiva: só aceitas se não habilitam documento
                // e a superfície declara MIXED/PROSPECTION ou proíbe documento.
                if ($contract->allowsDocument
                    && $contract->officialState === MonitoringOfficialStateSummary::Production
                ) {
                    $errors[] = "{$key}: op não-PRODUCTION {$opKey} em superfície PRODUCTION com documento";
                }
            }
        }

        $hierarchicalKeys = [];
        foreach ($contract->capabilities() as $capability) {
            if ($capability->capabilityKey === '' || $capability->actions === []) {
                $errors[] = "{$key}: capability vazia ou sem actions";
            }
            foreach ($capability->actions as $action) {
                $hierarchicalKeys[] = $action->operationKey;
                if (! isset($index[$action->operationKey])) {
                    $errors[] = "{$key}: action órfã do manifesto: {$action->actionKey}";

                    continue;
                }
                if ($action->handler === 'none' && $action->available) {
                    $errors[] = "{$key}: action sem handler marcada disponível: {$action->actionKey}";
                }
                if ($action->operationClass !== FiscalOperationClass::Read && $action->available) {
                    $errors[] = "{$key}: action não-READ marcada disponível: {$action->actionKey}";
                }
                if ((bool) ($index[$action->operationKey]['is_mutating'] ?? true)
                    && $action->operationClass === FiscalOperationClass::Read
                ) {
                    $errors[] = "{$key}: action mutante classificada como READ: {$action->actionKey}";
                }
            }
        }
        $expectedKeys = $contract->operationKeys;
        sort($expectedKeys);
        sort($hierarchicalKeys);
        if ($expectedKeys !== $hierarchicalKeys) {
            $errors[] = "{$key}: hierarchy diverge de operation_keys";
        }

        if (in_array($key, ['simples_mei_pgdasd', 'simples_mei_pgmei'], true)
            && $contract->routePattern !== '/monitoring/simples-mei'
        ) {
            $errors[] = "{$key}: rota canônica divergente";
        }
        if (in_array($key, ['dctfweb', 'mit'], true)
            && $contract->routePattern !== '/monitoring/dctfweb'
        ) {
            $errors[] = "{$key}: rota canônica divergente";
        }

        if ($contract->channel === MonitoringChannel::Integra
            && $contract->resultKind !== MonitoringResultKind::Unavailable
            && $contract->resultKind !== MonitoringResultKind::Aggregate
            && $contract->operationKeys === []
        ) {
            $errors[] = "{$key}: canal INTEGRA estruturado/PDF exige operation_keys";
        }

        if ($states !== []) {
            $unique = array_values(array_unique($states));
            $allProduction = $unique === [SerproOfficialState::Production->value];
            $allProspection = count($unique) === 1
                && $unique[0] === SerproOfficialState::Prospection->value;
            $hasNonProduction = ! $allProduction;

            if ($contract->officialState === MonitoringOfficialStateSummary::Production && $hasNonProduction) {
                $errors[] = "{$key}: official_state PRODUCTION com ops não produtivas";
            }
            if ($contract->officialState === MonitoringOfficialStateSummary::Prospection && ! $allProspection) {
                $errors[] = "{$key}: official_state PROSPECTION exige todas as ops em prospecção";
            }
        }

        return $errors;
    }

    /**
     * @param  array{entries: list<array<string, mixed>>}  $manifest
     * @return array<string, array<string, mixed>>
     */
    public function indexByOperationKey(array $manifest): array
    {
        $index = [];
        foreach ($manifest['entries'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $op = (string) ($entry['operation_key'] ?? '');
            if ($op === '') {
                continue;
            }
            if (isset($index[$op])) {
                throw new InvalidArgumentException("operation_key duplicada no catálogo: {$op}");
            }
            $index[$op] = $entry;
        }

        return $index;
    }
}
