<?php

namespace App\Actions\Serpro;

use App\Actions\Auth\RequireRecentPasswordConfirmationAction;
use App\Exceptions\SerproConfigurationException;
use App\Models\SerproCredentialVersion;
use App\Models\User;
use App\Services\Serpro\SerproCredentialVersionService;
use Illuminate\Http\Request;
use RuntimeException;

final readonly class VerifySerproCredentialVersionAction
{
    public function __construct(
        private SerproCredentialVersionService $credentials,
        private RequireRecentPasswordConfirmationAction $requirePassword,
    ) {}

    public function __invoke(
        SerproCredentialVersion $version,
        User $actor,
        Request $request,
    ): SerproCredentialVersion {
        ($this->requirePassword)($actor, $request);

        try {
            return $this->credentials->verifyPending($version, $actor->id);
        } catch (RuntimeException) {
            throw SerproConfigurationException::credentialVerificationRejected();
        }
    }
}
