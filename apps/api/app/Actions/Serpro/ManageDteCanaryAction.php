<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\DteCanaryDisableData;
use App\DTO\Serpro\DteCanaryExecutionResult;
use App\DTO\Serpro\DteCanaryPromotionData;
use App\DTO\Serpro\DteCanaryReconciliationData;
use App\DTO\Serpro\DteCanarySummaryFilterData;
use App\DTO\Serpro\DteCanarySummaryResult;
use App\DTO\Serpro\DteCanaryTargetData;
use App\Exceptions\DteCanaryApiException;
use App\Models\SerproDteCanaryRequest;
use App\Models\SerproDteControl;
use App\Models\User;
use App\Services\Serpro\SerproDteCanaryException;
use App\Services\Serpro\SerproDteCanaryService;
use Closure;

final readonly class ManageDteCanaryAction
{
    public function __construct(
        private SerproDteCanaryService $canary,
    ) {}

    public function summary(DteCanarySummaryFilterData $filter): DteCanarySummaryResult
    {
        return $this->canary->globalSummary($filter->requestId);
    }

    public function create(User $actor): SerproDteCanaryRequest
    {
        return $this->adapt(
            fn (): SerproDteCanaryRequest => $this->canary->createRequest($actor->id),
            'dte_create_error',
        );
    }

    public function selectTarget(
        SerproDteCanaryRequest $request,
        DteCanaryTargetData $target,
        User $actor,
    ): SerproDteCanaryRequest {
        return $this->adapt(
            fn (): SerproDteCanaryRequest => $this->canary->selectTarget(
                $request,
                $target->tenantId,
                $target->clientId,
                $actor->id,
            ),
            'dte_target_error',
        );
    }

    public function approveOwner(
        SerproDteCanaryRequest $request,
        User $actor,
    ): SerproDteCanaryRequest {
        return $this->adapt(
            fn (): SerproDteCanaryRequest => $this->canary->approveAsOwner($request, $actor),
            'dte_owner_approve_error',
        );
    }

    public function execute(
        SerproDteCanaryRequest $request,
        User $actor,
    ): DteCanaryExecutionResult {
        return $this->adapt(
            fn (): DteCanaryExecutionResult => $this->canary->execute($request, $actor->id),
            'dte_execute_blocked',
        );
    }

    public function reconcile(
        SerproDteCanaryRequest $request,
        DteCanaryReconciliationData $data,
        User $actor,
    ): SerproDteCanaryRequest {
        return $this->adapt(
            fn (): SerproDteCanaryRequest => $this->canary->reconcile(
                $request,
                $actor,
                $data->reference,
                $data->summary,
            ),
            'dte_reconcile_error',
        );
    }

    public function promoteLimited(
        SerproDteCanaryRequest $request,
        DteCanaryPromotionData $data,
        User $actor,
    ): SerproDteControl {
        return $this->adapt(
            fn (): SerproDteControl => $this->canary->promoteLimited(
                $request,
                $actor,
                $data->confirmationPhrase,
                $data->reason,
                $data->changeWindowStart,
                $data->changeWindowEnd,
                $data->maxQuantity,
            ),
            'dte_promote_error',
        );
    }

    public function disable(DteCanaryDisableData $data, User $actor): SerproDteControl
    {
        return $this->adapt(
            fn (): SerproDteControl => $this->canary->disable(
                $actor,
                $data->confirmationPhrase,
                $data->reason,
            ),
            'dte_disable_error',
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function adapt(Closure $operation, string $stableCode): mixed
    {
        try {
            return $operation();
        } catch (SerproDteCanaryException $error) {
            throw DteCanaryApiException::fromDomain($error, $stableCode);
        }
    }
}
