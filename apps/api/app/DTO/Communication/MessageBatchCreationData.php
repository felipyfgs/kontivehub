<?php

namespace App\DTO\Communication;

final readonly class MessageBatchCreationData
{
    /** @param list<MessageCreationData> $items */
    public function __construct(
        public string $clientBatchId,
        public string $requestDigest,
        public array $items,
    ) {}
}
