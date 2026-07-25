<?php

namespace App\Services\Platform;

use RuntimeException;

/**
 * Erros de domínio do Proprietário singleton (PLATFORM_ADMIN).
 */
final class PlatformOwnerException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function alreadyExists(
        string $message = 'Já existe um Proprietário da instalação.',
        ?\Throwable $previous = null,
    ): self {
        return new self($message, 'platform_owner_already_exists', 409, $previous);
    }

    public static function notFound(
        string $message = 'Proprietário da instalação não encontrado.',
    ): self {
        return new self($message, 'platform_owner_not_found', 404);
    }

    public static function cannotRemove(
        string $message = 'Não é permitido remover o único Proprietário. Use o fluxo de recuperação ou transferência.',
    ): self {
        return new self($message, 'platform_owner_cannot_remove', 409);
    }

    public static function invalid(
        string $message,
        string $code = 'platform_owner_invalid',
        int $status = 422,
    ): self {
        return new self($message, $code, $status);
    }
}
