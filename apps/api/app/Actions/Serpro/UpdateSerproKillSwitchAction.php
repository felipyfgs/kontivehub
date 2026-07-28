<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\SerproKillSwitchData;
use App\Exceptions\SerproKillSwitchException;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Serpro\SerproKillSwitchService;
use App\Services\Serpro\SerproRolloutApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class UpdateSerproKillSwitchAction
{
    public function __construct(
        private SerproKillSwitchService $killSwitch,
        private SerproRolloutApprovalService $rollouts,
        private RecentPasswordConfirmationGate $passwordGate,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(
        SerproKillSwitchData $data,
        User $actor,
        Request $request,
    ): array {
        if ($data->active) {
            if ($data->isSolutionScoped()) {
                $this->killSwitch->activateSolution((string) $data->solution, $data->reason, $actor->id);
            } else {
                $this->killSwitch->activateGlobal($data->reason, $actor->id);
            }

            return ['data' => $this->killSwitch->status()];
        }

        if (! $this->passwordGate->isRecentlyConfirmed($actor, $request)) {
            throw SerproKillSwitchException::passwordConfirmationRequired();
        }

        if ($data->changeWindowStart === null || $data->changeWindowEnd === null) {
            throw SerproKillSwitchException::ownerConfirmationFailed();
        }

        try {
            $result = DB::transaction(function () use ($data, $actor): array {
                $action = $data->isSolutionScoped()
                    ? SerproRolloutApprovalService::ACTION_KILL_SWITCH_SOLUTION_OFF
                    : SerproRolloutApprovalService::ACTION_KILL_SWITCH_OFF;
                $subjectType = $data->isSolutionScoped()
                    ? 'KILL_SWITCH_SOLUTION'
                    : 'KILL_SWITCH';
                $context = $data->isSolutionScoped()
                    ? ['solution' => (string) $data->solution]
                    : [];

                $approval = $this->rollouts->request(
                    action: $action,
                    subjectType: $subjectType,
                    subjectId: null,
                    reason: $data->reason,
                    requestedByUserId: $actor->id,
                    context: $context,
                    changeWindowStart: $data->changeWindowStart,
                    changeWindowEnd: $data->changeWindowEnd,
                    fromHttp: true,
                );

                return $this->rollouts->approve(
                    $approval,
                    $actor->id,
                    passwordRecentlyConfirmed: true,
                    reason: $data->reason,
                    confirmationPhrase: $data->confirmationPhrase,
                    changeWindowStart: $data->changeWindowStart,
                    changeWindowEnd: $data->changeWindowEnd,
                    fromHttp: true,
                );
            }, 3);
        } catch (RuntimeException) {
            throw SerproKillSwitchException::ownerConfirmationFailed();
        }

        return [
            'data' => $this->killSwitch->status(),
            'approval' => $this->rollouts->toSanitized($result['approval']),
            'executed' => $result['executed'],
            'message' => $result['executed']
                ? (
                    $data->isSolutionScoped()
                        ? 'Kill switch de solução desativado.'
                        : 'Kill switch global desativado.'
                )
                : 'Confirmação registrada; execução pendente de gates.',
        ];
    }
}
