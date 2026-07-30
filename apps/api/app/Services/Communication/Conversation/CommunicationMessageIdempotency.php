<?php

namespace App\Services\Communication\Conversation;

use Illuminate\Support\Str;

final class CommunicationMessageIdempotency
{
    public function namespace(bool $outboundInitiation): string
    {
        return $outboundInitiation
            ? 'outbound-initiation'
            : 'conversation-message';
    }

    public function providerId(?string $idempotencyKey, bool $outboundInitiation): string
    {
        if ($idempotencyKey === null) {
            return 'message-'.strtolower((string) Str::ulid());
        }

        $digestSource = $outboundInitiation
            ? $this->namespace(true)."\0".$idempotencyKey
            : $idempotencyKey;

        return 'message-'.substr(hash('sha256', $digestSource), 0, 40);
    }
}
