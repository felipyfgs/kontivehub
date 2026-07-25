<?php

namespace App\Enums;

/**
 * Triagem operacional interna — distinta de leitura/ciência oficial remota.
 */
enum MailboxTriageStatus: string
{
    case New = 'NEW';
    case InReview = 'IN_REVIEW';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nova',
            self::InReview => 'Em análise',
            self::Resolved => 'Resolvida',
        };
    }
}
