<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\UsageReconciliationData;
use App\Models\User;
use App\Services\Serpro\Usage\UsageReconciliationService;

final readonly class RegisterUsageReconciliationAction
{
    public function __construct(
        private UsageReconciliationService $reconciliation,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(UsageReconciliationData $data, User $actor): array
    {
        $reconciliation = $this->reconciliation->registerOfficialInvoice(
            year: $data->year,
            month: $data->month,
            officialTotalCostMicros: $data->officialTotalCostMicros,
            officialReference: $data->officialReference,
            officialSource: $data->officialSource,
            notes: $data->notes,
            importedByUserId: $actor->id,
            adjustments: $data->adjustmentPayloads(),
            differenceCause: $data->differenceCause,
            recomputeAggregates: $data->recomputeAggregates,
        );

        return $reconciliation->toPlatformArray();
    }
}
