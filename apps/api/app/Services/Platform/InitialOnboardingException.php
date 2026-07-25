<?php

namespace App\Services\Platform;

use RuntimeException;

final class InitialOnboardingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self('Onboarding não autorizado.', 'onboarding_not_authorized', 403);
    }

    public static function unavailable(): self
    {
        return new self('Onboarding indisponível.', 'onboarding_unavailable', 409);
    }

    public static function secureTransportRequired(): self
    {
        return new self('Onboarding produtivo exige HTTPS.', 'https_required', 403);
    }
}
