<?php

namespace App\Services\Activation;

use RuntimeException;

/**
 * Erro de domínio de ativação/onboarding com status HTTP e código estável.
 */
final class ActivationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $errorCode = 'activation_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function invalid(string $message = 'Ativação inválida ou expirada.'): self
    {
        return new self($message, 422, 'activation_invalid');
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self($message, 409, $code);
    }

    public static function forbidden(string $message = 'Ação não permitida.'): self
    {
        return new self($message, 403, 'forbidden');
    }

    public static function notFound(string $message = 'Recurso não encontrado.'): self
    {
        return new self($message, 404, 'not_found');
    }

    public static function seatLimit(string $message = 'Limite de usuários do plano atingido.'): self
    {
        return new self($message, 422, 'seat_limit_reached');
    }

    public static function emailTaken(string $message = 'Não foi possível concluir com o e-mail informado.'): self
    {
        return new self($message, 422, 'email_unavailable');
    }
}
