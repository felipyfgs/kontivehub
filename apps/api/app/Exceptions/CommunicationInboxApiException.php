<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationInboxApiException extends ApiDomainException implements ShouldntReport
{
    public static function versionConflict(): self
    {
        return new self(
            'version_conflict',
            'Inbox foi alterada por outro usuário.',
            409,
        );
    }

    public static function invalidMembership(): self
    {
        return new self(
            'invalid_inbox_membership',
            'Membership inválida para este escritório.',
            422,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }
}
