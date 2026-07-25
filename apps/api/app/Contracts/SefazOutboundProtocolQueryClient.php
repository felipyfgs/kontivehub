<?php

namespace App\Contracts;

use App\DTO\Outbound\ProtocolQueryResult;

/**
 * Consulta NFeConsultaProtocolo — não expõe envelope SOAP bruto ao domínio.
 */
interface SefazOutboundProtocolQueryClient
{
    /**
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function consult(
        string $accessKey,
        string $model,
        string $environment,
        array $certificate,
    ): ProtocolQueryResult;
}
