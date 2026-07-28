<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class FiscalDownloadData
{
    public function __construct(
        public string $bytes,
        public string $contentType,
        public string $filename,
    ) {}
}
