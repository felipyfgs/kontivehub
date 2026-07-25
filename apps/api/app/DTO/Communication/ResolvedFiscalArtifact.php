<?php

namespace App\DTO\Communication;

final readonly class ResolvedFiscalArtifact
{
    public function __construct(
        public string $type,
        public int $id,
        public string $digest,
        public string $periodKey,
        public string $contentType,
        public int $byteSize,
        public string $filename,
        public string $storageKind,
        public int $storageId,
    ) {}
}
