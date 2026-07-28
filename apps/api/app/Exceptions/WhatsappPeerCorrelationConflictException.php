<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class WhatsappPeerCorrelationConflictException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('WHATSAPP_PEER_CORRELATION_ACTIVE_FLOW_CONFLICT');
    }
}
