<?php

namespace App\Services\Integra\Mailbox;

final class MailboxIdempotency
{
    public static function messageHash(int $tenantId, int $clientId, string $externalId): string
    {
        return hash('sha256', implode('|', [
            'mailbox_msg',
            (string) $tenantId,
            (string) $clientId,
            strtoupper(trim($externalId)),
        ]));
    }
}
