<?php

namespace App\Actions\Tenant;

use App\DTO\Serpro\EligibilityResult;
use App\DTO\Tenant\SerproEligibilityData;
use App\Models\Client;
use App\Models\User;
use App\Services\Integra\IntegraEligibilityService;
use App\Support\CurrentTenant;

final readonly class EvaluateSerproEligibilityAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private IntegraEligibilityService $eligibility,
    ) {}

    public function __invoke(
        SerproEligibilityData $data,
        User $actor,
    ): EligibilityResult {
        $tenant = $this->currentTenant->tenant();
        $client = Client::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($data->clientId);

        return $this->eligibility->evaluate(
            $tenant,
            $client,
            $data->solutionCode,
            $data->serviceCode,
            $data->operationCode,
            $data->environment,
            $actor,
            $data->module,
        );
    }
}
