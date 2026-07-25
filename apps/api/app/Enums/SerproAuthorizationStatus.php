<?php

namespace App\Enums;

enum SerproAuthorizationStatus: string
{
    case Draft = 'DRAFT';
    case PendingTerm = 'PENDING_TERM';
    case TermValid = 'TERM_VALID';
    case TokenActive = 'TOKEN_ACTIVE';
    case ActionRequired = 'ACTION_REQUIRED';
    case Blocked = 'BLOCKED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';

    public function allowsExternalCalls(): bool
    {
        return in_array($this, [self::TokenActive, self::TermValid], true);
    }

    public function requiresInteractiveSignature(): bool
    {
        return $this === self::ActionRequired;
    }
}
