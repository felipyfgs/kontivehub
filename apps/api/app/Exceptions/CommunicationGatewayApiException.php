<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationGatewayApiException extends ApiDomainException implements ShouldntReport
{
    public static function identityAddressUnavailable(): self
    {
        return new self(
            'identity_address_unavailable',
            'Identidade sem endereço utilizável.',
            422,
        );
    }

    public static function conversationAddressUnavailable(): self
    {
        return new self(
            'conversation_address_unavailable',
            'Identidade da conversa sem endereço utilizável.',
            422,
        );
    }

    public static function remoteMessageIdentifierUnavailable(): self
    {
        return new self(
            'remote_message_identifier_unavailable',
            'Mensagem sem identificador remoto.',
            422,
        );
    }

    public static function outboundMessageRequired(): self
    {
        return new self(
            'outbound_message_required',
            'A operação exige uma mensagem enviada.',
            422,
        );
    }

    public static function inboundMessageRequired(): self
    {
        return new self(
            'inbound_message_required',
            'A operação exige uma mensagem recebida.',
            422,
        );
    }

    public static function pollMessageRequired(): self
    {
        return new self(
            'poll_message_required',
            'A mensagem alvo precisa ser uma enquete.',
            422,
        );
    }

    public static function mediaNotRecoverable(): self
    {
        return new self(
            'media_not_recoverable',
            'A mídia não está disponível para recuperação.',
            422,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }
}
