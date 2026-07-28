<?php

namespace App\Actions\Communication;

use App\Services\Communication\Events\CommunicationGatewayEventBoundaryValidator;
use App\Services\Communication\Events\GatewayEventIngestor;

final readonly class IngestCommunicationGatewayEventAction
{
    public function __construct(
        private CommunicationGatewayEventBoundaryValidator $validator,
        private GatewayEventIngestor $ingestor,
    ) {}

    /** @return 'processed'|'duplicate'|'ignored' */
    public function execute(string $body): string
    {
        return $this->ingestor->ingest(
            $this->validator->validate($body),
        );
    }
}
