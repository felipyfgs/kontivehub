<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\SerproBudgetFilterData;
use App\DTO\Serpro\SerproReadinessFilterData;
use App\DTO\Serpro\SerproRolloutApprovalData;
use App\DTO\Serpro\SerproRolloutApprovalResult;
use App\DTO\Serpro\SerproRolloutCreationData;
use App\DTO\Serpro\SerproRolloutFilterData;
use App\DTO\Serpro\SerproRolloutRejectionData;
use App\Enums\SerproEnvironment;
use App\Exceptions\SerproRolloutApiException;
use App\Models\SerproCredentialVersion;
use App\Models\SerproReadinessRun;
use App\Models\SerproRolloutApproval;
use App\Models\SerproUsageBudget;
use App\Models\User;
use App\Services\Serpro\SerproKillSwitchService;
use App\Services\Serpro\SerproMetricsExporter;
use App\Services\Serpro\SerproReadinessService;
use App\Services\Serpro\SerproRolloutApprovalService;
use App\Services\Serpro\SerproRolloutException;
use Closure;
use Illuminate\Database\Eloquent\Collection;

final readonly class ManageSerproPlatformOperationsAction
{
    public function __construct(
        private SerproReadinessService $readiness,
        private SerproRolloutApprovalService $rollouts,
        private SerproKillSwitchService $killSwitch,
        private SerproMetricsExporter $metrics,
    ) {}

    /** @return Collection<int, SerproCredentialVersion> */
    public function credentialVersions(?SerproEnvironment $environment): Collection
    {
        $query = SerproCredentialVersion::query()
            ->with([
                'connectionEvidences' => fn ($query) => $query
                    ->where('success', true)
                    ->where('invalidated', false)
                    ->where('expires_at', '>', now())
                    ->orderByDesc('tested_at'),
            ])
            ->orderByDesc('id')
            ->limit(100);

        if ($environment !== null) {
            $query->where('environment', $environment->value);
        }

        return $query->get();
    }

    /** @return SerproReadinessRun|array<string, mixed> */
    public function readiness(SerproReadinessFilterData $filter, User $actor): SerproReadinessRun|array
    {
        return $this->readiness->evaluateGlobal(
            $filter->environment,
            persist: $filter->persist,
            actorUserId: $actor->id,
            trigger: 'API',
        );
    }

    /** @return array<string, mixed> */
    public function metrics(): array
    {
        return $this->metrics->snapshot();
    }

    /** @return Collection<int, SerproUsageBudget> */
    public function budgets(SerproBudgetFilterData $filter): Collection
    {
        $query = SerproUsageBudget::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->limit(100);

        if ($filter->scope !== null) {
            $query->where('scope', $filter->scope);
        }

        return $query->get();
    }

    /** @return Collection<int, SerproRolloutApproval> */
    public function rollouts(SerproRolloutFilterData $filter): Collection
    {
        $query = SerproRolloutApproval::query()
            ->orderByDesc('id')
            ->limit(50);

        if ($filter->status !== null) {
            $query->where('status', $filter->status);
        }

        return $query->get();
    }

    public function requestRollout(
        SerproRolloutCreationData $data,
        User $actor,
    ): SerproRolloutApproval {
        return $this->adapt(
            fn (): SerproRolloutApproval => $this->rollouts->request(
                action: $data->action,
                subjectType: $data->subjectType,
                subjectId: $data->subjectId,
                reason: $data->reason,
                requestedByUserId: $actor->id,
                environment: $data->environment,
                tenantId: $data->tenantId,
                context: $data->context,
                ttlHours: $data->ttlHours,
                changeWindowStart: $data->changeWindowStart,
                changeWindowEnd: $data->changeWindowEnd,
                fromHttp: true,
            ),
            'serpro_rollout_request_failed',
        );
    }

    public function approveRollout(
        SerproRolloutApproval $approval,
        SerproRolloutApprovalData $data,
        User $actor,
    ): SerproRolloutApprovalResult {
        return $this->adapt(function () use ($approval, $data, $actor): SerproRolloutApprovalResult {
            $result = $this->rollouts->approve(
                $approval,
                $actor->id,
                passwordRecentlyConfirmed: true,
                reason: $data->reason,
                confirmationPhrase: $data->confirmationPhrase,
                changeWindowStart: $data->changeWindowStart,
                changeWindowEnd: $data->changeWindowEnd,
                fromHttp: true,
            );

            return new SerproRolloutApprovalResult(
                approval: $result['approval'],
                executed: $result['executed'],
                killSwitch: $this->killSwitch->status(),
            );
        }, 'serpro_rollout_approval_failed');
    }

    public function rejectRollout(
        SerproRolloutApproval $approval,
        SerproRolloutRejectionData $data,
        User $actor,
    ): SerproRolloutApproval {
        return $this->adapt(
            fn (): SerproRolloutApproval => $this->rollouts->reject(
                $approval,
                $actor->id,
                $data->reason,
            ),
            'serpro_rollout_rejection_failed',
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
        } catch (SerproRolloutException $error) {
            throw SerproRolloutApiException::fromDomain($error, $stableCode);
        }
    }
}
