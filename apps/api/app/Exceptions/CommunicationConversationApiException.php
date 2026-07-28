<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationConversationApiException extends ApiDomainException implements ShouldntReport
{
    public static function versionConflict(): self
    {
        return new self(
            'version_conflict',
            'Conversa foi alterada por outro usuário.',
            409,
        );
    }

    public static function readStateVersionConflict(int $currentVersion): self
    {
        return new self(
            'READ_STATE_VERSION_CONFLICT',
            'O estado de leitura foi alterado em outra sessão.',
            409,
            ['current_version' => $currentVersion],
        );
    }

    public static function purged(): self
    {
        return new self(
            'conversation_purged',
            'Conversa removida por solicitação de privacidade.',
            410,
        );
    }

    public static function snoozedUntilRequired(): self
    {
        return new self(
            'snoozed_until_required',
            'Informe snoozed_until para adiar a conversa.',
            422,
        );
    }

    public static function assigneeCannotAccessInbox(): self
    {
        return new self(
            'assignee_inbox_access_required',
            'Responsável sem acesso à inbox.',
            422,
        );
    }

    public static function internalNoteAttachment(): self
    {
        return new self(
            'internal_note_attachment',
            'Notas internas não aceitam anexos.',
            422,
        );
    }

    public static function unsupportedMessageKind(?string $message = null): self
    {
        return new self(
            'MESSAGE_KIND_UNSUPPORTED',
            $message ?? 'Este tipo de mensagem não é suportado para envio.',
            422,
        );
    }

    public static function idempotencyConflict(): self
    {
        return new self(
            'idempotency_conflict',
            'Idempotency key reutilizada com outro conteúdo.',
            409,
        );
    }

    public static function invalidAttachment(): self
    {
        return new self(
            'invalid_attachment',
            'Arquivo inválido.',
            422,
        );
    }

    public static function attachmentIntegrityFailure(): self
    {
        return new self(
            'attachment_integrity_failure',
            'Falha de integridade no anexo.',
            422,
        );
    }

    /** @param array<string, mixed> $responseData */
    private function __construct(
        string $code,
        string $message,
        int $status,
        array $responseData = [],
    )
    {
        parent::__construct($code, $message, $status, $responseData);
    }
}
