<?php

namespace App\DTO\Communication;

final readonly class CommunicationCannedResponseRenderResult
{
    public function __construct(
        public int $cannedResponseId,
        public int $conversationId,
        public string $body,
    ) {}
}
