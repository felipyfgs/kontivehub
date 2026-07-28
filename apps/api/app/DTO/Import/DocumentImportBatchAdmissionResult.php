<?php

namespace App\DTO\Import;

use App\Models\DocumentImportBatch;

final readonly class DocumentImportBatchAdmissionResult
{
    public function __construct(
        public DocumentImportBatch $batch,
        public bool $created,
    ) {}
}
