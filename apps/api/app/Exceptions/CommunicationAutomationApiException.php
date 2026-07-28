<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationAutomationApiException extends ApiDomainException implements ShouldntReport
{
    public static function policyVersionConflict(): self
    {
        return new self(
            'version_conflict',
            'Política alterada por outro usuário.',
            409,
        );
    }

    public static function preferenceRequired(): self
    {
        return new self(
            'communication_preference_required',
            'Configure a preferência fiscal antes dos destinatários.',
            422,
        );
    }

    public static function recipientVersionConflict(): self
    {
        return new self(
            'version_conflict',
            'Preferência alterada por outro usuário.',
            409,
        );
    }

    public static function ineligibleRecipient(): self
    {
        return new self(
            'ineligible_recipient',
            'Destinatário não elegível para este cliente.',
            422,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }
}
