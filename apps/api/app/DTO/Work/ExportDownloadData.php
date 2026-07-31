<?php

namespace App\DTO\Work;

final readonly class ExportDownloadData
{
    public function __construct(
        public string $storagePath,
        public string $filename,
    ) {}
}
