<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use App\Models\Client;
use App\Models\Office;
use App\Services\Fiscal\ManualConsult\ManualConsultActionCatalog;
use App\Services\Fiscal\ManualConsult\ManualConsultExecutionService;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fachada allowlisted das 13 operações produtivas de leitura/apoio. */
final class DeclarationOperationReadService
{
    public function __construct(
        private readonly DeclarationOperationRegistry $registry,
        private readonly DeclarationOperationInputValidator $inputs,
        private readonly OfficialServiceCatalogManifest $manifest,
        private readonly ManualConsultActionCatalog $manualCatalog,
        private readonly ManualConsultExecutionService $manualExecution,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(
        Office $office,
        Client $client,
        string $actionId,
        array $params,
        bool $confirmed,
        ?int $actorUserId,
    ): array {
        $operationKey = $this->registry->operationKeyFor($actionId);
        $entry = $this->manifest->findByOperationKey($this->manifest->load(), $operationKey);

        if (($entry['official_state'] ?? null) !== SerproOfficialState::Production->value) {
            throw new HttpException(422, 'OPERATION_NOT_PRODUCTION');
        }
        if ((bool) ($entry['is_mutating'] ?? true)) {
            throw new HttpException(422, 'OPERATION_REQUIRES_PREFLIGHT');
        }
        if (! in_array((string) ($entry['platform_support'] ?? ''), [
            SerproPlatformSupport::Implemented->value,
            SerproPlatformSupport::ProductionValidated->value,
        ], true)) {
            throw new HttpException(422, 'OPERATION_NOT_IMPLEMENTED');
        }

        $definition = $this->manualCatalog->findByOperationKey($operationKey);
        if ($definition === null || ! $definition->hasHandler) {
            throw new HttpException(422, 'OPERATION_HANDLER_MISSING');
        }

        return $this->manualExecution->execute(
            office: $office,
            client: $client,
            actionId: $definition->actionId,
            params: $this->toManualParams($operationKey, $this->inputs->validate($operationKey, $params)),
            confirmed: $confirmed,
            actorUserId: $actorUserId,
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function toManualParams(string $operationKey, array $params): array
    {
        return match ($operationKey) {
            'pgdasd.consdeclaracao' => array_filter([
                'year' => $params['calendar_year'] ?? null,
                'period_key' => $params['period_key'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'pgdasd.consdecrec' => [
                'period_key' => $params['period_key'],
                'declaration_number' => $params['declaration_number'],
            ],
            'pgdasd.consextrato' => ['numero_das' => $params['das_number']],
            'defis.consultimadecrec' => ['year' => $params['calendar_year']],
            'mit.situacaoenc' => [
                'period_key' => $params['period_key'],
                'protocolo_encerramento' => $params['closing_protocol'],
            ],
            'mit.consapuracao' => [
                'period_key' => $params['period_key'],
                'id_apuracao' => $params['assessment_id'],
            ],
            'mit.listaapuracoes' => array_filter([
                'anoApuracao' => $params['calendar_year'] ?? null,
                'mesApuracao' => $params['month'] ?? null,
                'situacaoApuracao' => $params['status'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            default => $params,
        };
    }
}
