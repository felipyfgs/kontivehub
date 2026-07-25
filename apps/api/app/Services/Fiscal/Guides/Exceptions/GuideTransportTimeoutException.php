<?php

namespace App\Services\Fiscal\Guides\Exceptions;

/**
 * Timeout/falha de transporte após possível envio remoto.
 * Deve resultar em UNKNOWN_RESULT — nunca retry imediato.
 */
final class GuideTransportTimeoutException extends GuideException
{
    public function __construct(
        string $message = 'Timeout após envio da emissão de guia.',
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message, 'guide_transport_timeout', 504, [
            'correlation_id' => $correlationId,
        ]);
    }
}
