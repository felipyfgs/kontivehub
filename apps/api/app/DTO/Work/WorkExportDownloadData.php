<?php

namespace App\DTO\Work;

final readonly class WorkExportDownloadData
{
    public function __construct(
        public string $storagePath,
        public string $filename,
    ) {}
}
