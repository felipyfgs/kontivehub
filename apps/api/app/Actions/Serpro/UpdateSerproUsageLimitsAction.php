<?php

namespace App\Actions\Serpro;

use App\Actions\Auth\RequireRecentPasswordConfirmationAction;
use App\DTO\Serpro\UsageLimitsUpdateData;
use App\DTO\Serpro\UsageLimitsUpdateResult;
use App\Exceptions\SerproConfigurationException;
use App\Models\User;
use App\Services\Serpro\SerproQuantityUsageLimitService;
use Illuminate\Http\Request;
use RuntimeException;

final readonly class UpdateSerproUsageLimitsAction
{
    public function __construct(
        private SerproQuantityUsageLimitService $quantityLimits,
        private RequireRecentPasswordConfirmationAction $requirePassword,
    ) {}

    public function __invoke(
        UsageLimitsUpdateData $data,
        User $actor,
        Request $request,
    ): UsageLimitsUpdateResult {
        ($this->requirePassword)($actor, $request);

        try {
            $configuration = $this->quantityLimits->upsert(
                $data->environment,
                $data->cycleStartDay,
                $data->alertPercent,
                $data->globalLimitQuantity,
                $data->tenantLimitPayloads(),
                $actor->id,
            );
        } catch (RuntimeException) {
            throw SerproConfigurationException::usageLimitsRejected();
        }

        return new UsageLimitsUpdateResult(
            configuration: $configuration,
            tenantLimits: $this->quantityLimits->listTenantLimits($data->environment),
        );
    }
}
