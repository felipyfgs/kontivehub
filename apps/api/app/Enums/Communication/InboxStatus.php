<?php

namespace App\Enums\Communication;

enum InboxStatus: string
{
    case Disconnected = 'DISCONNECTED';
    case Connecting = 'CONNECTING';
    case Connected = 'CONNECTED';

    public function canTransport(): bool
    {
        return $this === self::Connected;
    }
}
