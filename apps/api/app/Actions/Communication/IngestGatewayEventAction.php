<?php

namespace App\Actions\Communication;

use App\Services\Communication\Events\GatewayEventBoundaryValidator;
use App\Services\Communication\Events\GatewayEventIngestor;

final readonly class IngestGatewayEventAction
{
    public function __construct(
        private GatewayEventBoundaryValidator $validator,
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
