<?php

namespace App\DTO\Communication;

final readonly class ConversationListSnapshotData
{
    /** @param list<int> $conversationIds */
    public function __construct(
        public string $token,
        public array $conversationIds,
        public string $expiresAt,
    ) {}
}
