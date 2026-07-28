<?php

namespace App\Actions\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalEmissionData;
use App\DTO\FgtsDigital\FgtsDigitalEmissionResult;
use App\DTO\FgtsDigital\FgtsDigitalPreviewData;
use App\DTO\FgtsDigital\FgtsDigitalPreviewResult;
use App\Jobs\Fiscal\ExecuteFgtsDigitalRunJob;
use App\Models\User;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use App\Services\FgtsDigital\FgtsDigitalQuery;
use App\Services\FgtsDigital\FgtsDigitalReadinessService;
use App\Support\CurrentTenant;

final readonly class ManageFgtsDigitalGuideAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FgtsDigitalQuery $query,
        private FgtsDigitalReadinessService $readiness,
        private FgtsDigitalPortalService $portal,
    ) {}

    public function preview(
        User $actor,
        FgtsDigitalPreviewData $data,
    ): FgtsDigitalPreviewResult {
        $tenant = $this->currentTenant->tenant();
        $client = $this->query->client($data->clientId);
        $readiness = $this->readiness->check($tenant, $client);
        if (! $readiness->readyForRead) {
            throw FgtsDigitalException::readinessBlocked($readiness);
        }

        $result = $this->portal->preview(
            $tenant,
            $client,
            $actor,
            $data->guideType,
            $data->parameters,
        );

        return new FgtsDigitalPreviewResult(
            run: $result['run'],
            previewToken: $result['preview_token'],
        );
    }

    public function emit(
        User $actor,
        FgtsDigitalEmissionData $data,
    ): FgtsDigitalEmissionResult {
        $tenant = $this->currentTenant->tenant();
        $preview = $this->query->previewRun($data->previewRunId);
        $result = $this->portal->authorizeEmission(
            $tenant,
            $preview,
            $actor,
            $data->previewToken,
            $data->confirmationPhrase,
        );
        if (! $result['reused']) {
            ExecuteFgtsDigitalRunJob::dispatch(
                (int) $tenant->id,
                (int) $result['run']->id,
            )->afterCommit();
        }

        return new FgtsDigitalEmissionResult(
            run: $result['run'],
            reused: $result['reused'],
        );
    }
}
