<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationConversationBulkApiException extends ApiDomainException implements ShouldntReport
{
    public static function idempotencyKeyReused(): self
    {
        return new self(
            'IDEMPOTENCY_KEY_REUSED',
            'Idempotency-Key reutilizada com payload diferente.',
            409,
        );
    }

    public static function invalidItems(): self
    {
        return new self(
            'CONVERSATION_BULK_INVALID_ITEMS',
            'Um ou mais itens da seleção são inválidos para esta operação.',
            422,
        );
    }

    public static function invalidParams(): self
    {
        return new self(
            'CONVERSATION_BULK_INVALID_PARAMS',
            'Parâmetros da ação em lote inválidos.',
            422,
        );
    }

    public static function operationNotFound(): self
    {
        return new self(
            'CONVERSATION_BULK_NOT_FOUND',
            'Operação em lote não encontrada.',
            404,
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            'CONVERSATION_BULK_FORBIDDEN',
            'Ação não autorizada para o perfil atual.',
            403,
        );
    }

    private function __construct(
        string $code,
        string $message,
        int $status,
    ) {
        parent::__construct($code, $message, $status);
    }
}
