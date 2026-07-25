<?php

namespace App\Enums\Communication;

enum MessageDirection: string
{
    case Inbound = 'INBOUND';
    case Outbound = 'OUTBOUND';
    case Internal = 'INTERNAL';
}
