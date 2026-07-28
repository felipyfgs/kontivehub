<?php

namespace App\DTO\Communication;

final readonly class CommunicationCannedResponseRenderData
{
    public function __construct(
        public int $conversationId,
    ) {}
}
