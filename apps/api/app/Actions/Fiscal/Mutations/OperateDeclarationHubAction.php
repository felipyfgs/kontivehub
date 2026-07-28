<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\AttachDeclarationEvidenceData;
use App\DTO\Fiscal\Mutations\ProjectDeclarationData;
use App\DTO\Fiscal\Mutations\PublishDeclarationCalendarData;
use App\Models\TaxObligationDefinition;
use App\Services\Fiscal\Declarations\DeclarationHubQueryService;
use App\Services\Fiscal\Declarations\TaxDeadlineCalendarService;
use App\Services\Fiscal\Declarations\TaxDeliveryEvidenceService;
use App\Services\Fiscal\Declarations\TaxObligationProjectionService;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final readonly class OperateDeclarationHubAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FindFiscalClientAction $findClient,
        private TaxObligationProjectionService $projections,
        private TaxDeliveryEvidenceService $evidences,
        private TaxDeadlineCalendarService $deadlines,
        private DeclarationHubQueryService $hub,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>}|array{item: array<string, mixed>}
     */
    public function project(ProjectDeclarationData $data): array
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient->handle($tenant, $data->clientId);
        if ($client === null) {
            throw new NotFoundHttpException('Cliente não encontrado.');
        }

        if ($data->all) {
            $items = $this->projections->projectAllForClient(
                $tenant,
                $client,
                $data->periodKey,
                $data->periodYear,
                $data->periodMonth,
            );

            return [
                'items' => array_map(
                    fn ($p) => $p->toPublicArray(true),
                    $items,
                ),
            ];
        }

        $code = strtoupper((string) ($data->obligationCode ?? ''));
        if ($code === '') {
            throw new UnprocessableEntityHttpException(
                'obligation_code é obrigatório (ou all=true).',
            );
        }

        $definition = TaxObligationDefinition::query()->where('code', $code)->first();
        if ($definition === null) {
            throw new NotFoundHttpException('Obrigação não encontrada no catálogo.');
        }

        $projection = $this->projections->project(
            $tenant,
            $client,
            $definition,
            $data->periodKey,
            $data->periodYear,
            $data->periodMonth,
        );

        return ['item' => $projection->toPublicArray(true)];
    }

    /**
     * @return array{evidence: array<string, mixed>, projection: array<string, mixed>|null}
     */
    public function attachEvidence(
        int $projectionId,
        AttachDeclarationEvidenceData $data,
    ): array {
        $tenant = $this->currentTenant->tenant();
        $model = $this->hub->find($tenant, $projectionId);
        if ($model === null) {
            throw new NotFoundHttpException('Projeção de declaração não encontrada.');
        }

        $evidence = $this->evidences->attach(
            $tenant,
            $model,
            $data->toServicePayload(),
        );
        $fresh = $this->hub->find($tenant, $projectionId);

        return [
            'evidence' => $evidence->toPublicArray(),
            'projection' => $fresh?->toPublicArray(true),
        ];
    }

    /**
     * @return array{calendar: array<string, mixed>, recalculated: mixed}
     */
    public function publishCalendar(PublishDeclarationCalendarData $data): array
    {
        $result = $this->deadlines->publishCalendarVersion(
            code: $data->code,
            label: $data->label,
            rules: $data->rules,
            sourceRef: $data->sourceRef,
            notes: $data->notes,
            timezone: $data->timezone,
            recalculateOpen: $data->recalculateOpen,
        );

        return [
            'calendar' => $result['calendar']->toPublicArray(),
            'recalculated' => $result['recalculated'],
        ];
    }
}
