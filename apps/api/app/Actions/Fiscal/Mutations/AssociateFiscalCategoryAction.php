<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\AssociateFiscalCategoryBatchData;
use App\DTO\Fiscal\Mutations\AssociateFiscalCategoryData;
use App\Models\FiscalCategory;
use App\Models\User;
use App\Services\FiscalMonitoring\FiscalCategoryService;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AssociateFiscalCategoryAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FindFiscalClientAction $findClient,
        private FiscalCategoryService $categories,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function associate(?User $actor, AssociateFiscalCategoryData $data): array
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient->handle($tenant, $data->clientId);
        if ($client === null) {
            throw new NotFoundHttpException('Cliente não encontrado.');
        }

        $category = FiscalCategory::query()->findOrFail($data->fiscalCategoryId);
        $link = $this->categories->associate(
            $tenant,
            $client,
            $category,
            $actor?->id,
            $data->coverage,
            $data->status,
            $data->notes,
        );

        return $link->toPublicArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function associateBatch(
        ?User $actor,
        AssociateFiscalCategoryBatchData $data,
    ): array {
        $tenant = $this->currentTenant->tenant();
        $category = FiscalCategory::query()->findOrFail($data->fiscalCategoryId);

        return $this->categories->associateBatch(
            $tenant,
            $category,
            $data->clientIds,
            $actor?->id,
            $data->coverage,
        );
    }
}
