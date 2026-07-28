<?php

namespace App\DTO\FgtsDigital;

final readonly class FgtsDigitalReadinessData
{
    /**
     * @param  array<string, mixed>  $captcha
     * @param  list<array{code:string,message:string}>  $blockers
     */
    public function __construct(
        public string $driver,
        public bool $readyForRead,
        public bool $readyForMutation,
        public bool $mutationsEnabled,
        public ?string $credentialSource,
        public bool $hasAuthorizedSession,
        public ?FgtsDigitalSessionData $session,
        public bool $humanChallengePossible,
        public array $captcha,
        public array $blockers,
        public bool $supportsPdfDownload,
        public bool $supportsPixPayment,
    ) {}

    public function firstBlockerCode(): string
    {
        return $this->blockers[0]['code'] ?? 'FGTS_DIGITAL_NOT_READY';
    }

    public function firstBlockerMessage(): string
    {
        return $this->blockers[0]['message'] ?? 'FGTS Digital indisponível.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'ready_for_read' => $this->readyForRead,
            'ready_for_mutation' => $this->readyForMutation,
            'mutations_enabled' => $this->mutationsEnabled,
            'credential_source' => $this->credentialSource,
            'has_authorized_session' => $this->hasAuthorizedSession,
            'session' => $this->session?->toArray(),
            'human_challenge_possible' => $this->humanChallengePossible,
            'captcha' => $this->captcha,
            'blockers' => $this->blockers,
            'supports_pdf_download' => $this->supportsPdfDownload,
            'supports_pix_payment' => $this->supportsPixPayment,
        ];
    }
}
