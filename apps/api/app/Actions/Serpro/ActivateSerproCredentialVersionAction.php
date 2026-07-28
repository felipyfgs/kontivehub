<?php

namespace App\Actions\Serpro;

use App\Actions\Auth\RequireRecentPasswordConfirmationAction;
use App\DTO\Serpro\CredentialVersionActivationData;
use App\Exceptions\SerproConfigurationException;
use App\Models\SerproContract;
use App\Models\SerproCredentialVersion;
use App\Models\User;
use App\Services\Serpro\SerproCredentialVersionService;
use Illuminate\Http\Request;
use RuntimeException;

final readonly class ActivateSerproCredentialVersionAction
{
    public function __construct(
        private SerproCredentialVersionService $credentials,
        private RequireRecentPasswordConfirmationAction $requirePassword,
    ) {}

    public function __invoke(
        SerproCredentialVersion $version,
        CredentialVersionActivationData $data,
        User $actor,
        Request $request,
    ): SerproCredentialVersion {
        ($this->requirePassword)($actor, $request);

        $contract = $data->contractId !== null
            ? SerproContract::query()->find($data->contractId)
            : null;
        if ($data->contractId !== null && ! $contract instanceof SerproContract) {
            throw SerproConfigurationException::credentialActivationRejected();
        }

        try {
            return $this->credentials->activate(
                $version,
                contract: $contract,
                actorUserId: $actor->id,
                skipOauth: $data->skipOauth,
                approvalId: $data->approvalId,
            );
        } catch (RuntimeException) {
            throw SerproConfigurationException::credentialActivationRejected();
        }
    }
}
