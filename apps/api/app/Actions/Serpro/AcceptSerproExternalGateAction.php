<?php

namespace App\Actions\Serpro;

use App\Actions\Auth\RequireRecentPasswordConfirmationAction;
use App\DTO\Serpro\ExternalGateAcceptanceData;
use App\Exceptions\SerproConfigurationException;
use App\Models\SerproExternalGate;
use App\Models\User;
use App\Services\Serpro\SerproExternalGateService;
use Illuminate\Http\Request;
use RuntimeException;

final readonly class AcceptSerproExternalGateAction
{
    public function __construct(
        private SerproExternalGateService $externalGates,
        private RequireRecentPasswordConfirmationAction $requirePassword,
    ) {}

    public function __invoke(
        ExternalGateAcceptanceData $data,
        User $actor,
        Request $request,
    ): SerproExternalGate {
        ($this->requirePassword)($actor, $request);

        try {
            return $this->externalGates->acceptGate(
                $data->kind,
                $data->ticketReference,
                $data->answerSummary,
                $data->responsibleName,
                $data->referenceDate,
                $actor->id,
                $data->environment,
            );
        } catch (RuntimeException) {
            throw SerproConfigurationException::externalGateRejected();
        }
    }
}
