<?php

namespace App\DTO\Import;

use App\Models\User;
use Illuminate\Http\UploadedFile;

final readonly class DocumentImportBatchAdmissionData
{
    /** @param list<UploadedFile> $files */
    public function __construct(
        public User $actor,
        public array $files,
        public ?int $clientId,
        public ?int $establishmentId,
        public ?string $idempotencyKey,
    ) {}
}
