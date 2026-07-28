<?php

namespace App\DTO\Communication;

final readonly class CommunicationMessageUploadData
{
    public function __construct(
        public string $path,
        public string $originalName,
        public string $detectedMime,
        public string $clientMime,
    ) {}
}
