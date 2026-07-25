<?php

namespace App\Services\Fiscal\Guides\Exceptions;

use RuntimeException;

class GuideException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'guide_error',
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Guia não encontrada.'): self
    {
        return new self($message, 'guide_not_found', 404);
    }

    public static function forbidden(string $message, string $code = 'guide_forbidden'): self
    {
        return new self($message, $code, 403);
    }

    public static function challengeRequired(string $message = 'Confirmação reforçada e 2FA recente exigidos.'): self
    {
        return new self($message, 'high_risk_challenge_required', 403, [
            'requires_challenge' => true,
        ]);
    }

    public static function mutatingDisabled(): self
    {
        return new self(
            'Emissão de guias desabilitada (feature flag mutante).',
            'mutating_disabled',
            403,
        );
    }

    public static function retryBlocked(string $message = 'Retry bloqueado: resultado incerto em reconciliação.'): self
    {
        return new self($message, 'retry_blocked_unknown_result', 409);
    }

    public static function operationNotCataloged(string $system, string $service, string $operation): self
    {
        return new self(
            "Operação de guia não catalogada: {$system}/{$service}/{$operation}.",
            'operation_not_cataloged',
            422,
        );
    }
}
