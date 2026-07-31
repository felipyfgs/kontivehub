<?php

namespace App\DTO\Communication;

final readonly class ContactPurgeResult
{
    public function __construct(
        public int $contactId,
        public string $purgedAt,
        public int $deletedBlobs,
        public string $tombstoneDigest,
    ) {}
}
