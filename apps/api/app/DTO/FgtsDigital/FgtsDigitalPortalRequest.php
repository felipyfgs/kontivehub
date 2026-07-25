<?php

namespace App\DTO\FgtsDigital;

use App\Enums\FgtsDigitalOperation;
use App\Services\FgtsDigital\FgtsDigitalCaptchaConfig;

final readonly class FgtsDigitalPortalRequest
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public FgtsDigitalOperation $operation,
        public int $officeId,
        public int $clientId,
        public string $targetIdentifier,
        public ?string $credentialSource = null,
        public ?string $profileType = null,
        public array $parameters = [],
        public ?string $pfx = null,
        public ?string $pfxPassword = null,
        public ?array $storageState = null,
        public ?string $fixture = null,
    ) {}

    /** @return array<string, mixed> Sensitive transport envelope; never log or return from controllers. */
    public function toTransportArray(): array
    {
        return [
            'contract_version' => (int) config('fgts_digital.contract_version', 1),
            'operation' => $this->operation->value,
            'subject' => [
                'office_id' => $this->officeId,
                'client_id' => $this->clientId,
                'target_identifier' => $this->targetIdentifier,
                'credential_source' => $this->credentialSource,
                'profile_type' => $this->profileType,
            ],
            'parameters' => $this->parameters,
            'credential' => $this->pfx === null ? null : [
                'pfx_base64' => base64_encode($this->pfx),
                'passphrase' => $this->pfxPassword ?? '',
            ],
            'session' => $this->storageState,
            'fixture' => $this->fixture,
            'portal' => config('fgts_digital.portal'),
            'captcha' => app(FgtsDigitalCaptchaConfig::class)->privateTransportConfig(),
        ];
    }
}
