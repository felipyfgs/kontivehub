<?php

namespace App\Actions\Serpro;

use App\Actions\Auth\RequireRecentPasswordConfirmationAction;
use App\DTO\Serpro\CredentialVersionRegistrationData;
use App\Exceptions\SerproConfigurationException;
use App\Models\SerproContract;
use App\Models\SerproCredentialVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Serpro\SerproCredentialVersionService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final readonly class RegisterSerproCredentialVersionAction
{
    public function __construct(
        private SerproCredentialVersionService $credentials,
        private RequireRecentPasswordConfirmationAction $requirePassword,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        CredentialVersionRegistrationData $data,
        User $actor,
        Request $request,
    ): SerproCredentialVersion {
        ($this->requirePassword)($actor, $request);

        $contract = $data->contractId !== null
            ? SerproContract::query()->find($data->contractId)
            : null;
        if ($data->contractId !== null && ! $contract instanceof SerproContract) {
            throw SerproConfigurationException::credentialRegistrationRejected();
        }

        try {
            return $this->credentials->registerPending(
                $data->environment,
                $data->pfxBinary,
                $data->password,
                $data->consumerKey,
                $data->consumerSecret,
                $contract,
                $data->notes,
                $actor->id,
            );
        } catch (RuntimeException) {
            $this->auditFailure($actor);

            throw SerproConfigurationException::credentialRegistrationRejected();
        } catch (Throwable $error) {
            report($error);
            $this->auditFailure($actor);

            throw SerproConfigurationException::credentialRegistrationFailed();
        }
    }

    private function auditFailure(User $actor): void
    {
        $this->audit->record(
            action: 'serpro.credential.register_pending',
            result: 'FAILED',
            context: ['reason' => 'credential_registration_rejected'],
            userId: $actor->id,
        );
    }
}
