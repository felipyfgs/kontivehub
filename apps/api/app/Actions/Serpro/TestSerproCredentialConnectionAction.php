<?php

namespace App\Actions\Serpro;

use App\Actions\Auth\RequireRecentPasswordConfirmationAction;
use App\DTO\Serpro\CredentialConnectionResult;
use App\Exceptions\SerproConfigurationException;
use App\Models\SerproCredentialVersion;
use App\Models\User;
use App\Services\Serpro\SerproCredentialVersionService;
use Illuminate\Http\Request;
use RuntimeException;

final readonly class TestSerproCredentialConnectionAction
{
    public function __construct(
        private SerproCredentialVersionService $credentials,
        private RequireRecentPasswordConfirmationAction $requirePassword,
    ) {}

    public function __invoke(
        SerproCredentialVersion $version,
        User $actor,
        Request $request,
    ): CredentialConnectionResult {
        ($this->requirePassword)($actor, $request);

        try {
            $evidence = $this->credentials->testConnection($version, $actor->id);
        } catch (RuntimeException) {
            throw SerproConfigurationException::connectionTestRejected();
        }

        return new CredentialConnectionResult(
            evidence: $evidence,
            credentialVersion: $version->fresh(),
        );
    }
}
