<?php

namespace App\DTO\Outbound;

use App\Models\DocumentExport;
use App\Models\OutboundMonthlyReadiness;

final readonly class OutboundMonthlyExportResult
{
    public function __construct(
        public DocumentExport $export,
        public OutboundMonthlyReadiness $readiness,
        public bool $hasManifest,
    ) {}
}
