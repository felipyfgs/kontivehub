<?php

namespace App\DTO\Communication;

final readonly class CommunicationContactPurgeResult
{
    public function __construct(
        public int $contactId,
        public string $purgedAt,
        public int $deletedBlobs,
        public string $tombstoneDigest,
    ) {}
}
