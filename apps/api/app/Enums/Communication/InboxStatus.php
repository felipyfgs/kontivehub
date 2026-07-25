<?php

namespace App\Enums\Communication;

enum InboxStatus: string
{
    case Disconnected = 'DISCONNECTED';
    case Connecting = 'CONNECTING';
    case Connected = 'CONNECTED';

    public static function normalize(mixed $status): ?self
    {
        $value = strtoupper(trim((string) $status));

        return match ($value) {
            self::Disconnected->value,
            'DISABLED',
            'PROVISIONED',
            'DEGRADED',
            'REVOKED',
            'LOGGED_OUT',
            'LOGOUT' => self::Disconnected,
            self::Connecting->value,
            'PAIRING' => self::Connecting,
            self::Connected->value => self::Connected,
            default => null,
        };
    }

    public function canTransport(): bool
    {
        return $this === self::Connected;
    }
}
