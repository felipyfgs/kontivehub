<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationContactApiException extends ApiDomainException implements ShouldntReport
{
    public static function whatsappAlreadyAssigned(): self
    {
        return new self(
            'identity_conflict',
            'Este WhatsApp já pertence a um contato.',
            409,
        );
    }

    public static function identityAlreadyRegistered(): self
    {
        return new self(
            'identity_conflict',
            'Identidade já cadastrada.',
            409,
        );
    }

    public static function contactPurged(): self
    {
        return new self(
            'contact_purged',
            'Contato removido por solicitação de privacidade.',
            410,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }
}
