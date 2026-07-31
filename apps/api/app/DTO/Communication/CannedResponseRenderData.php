<?php

namespace App\DTO\Communication;

final readonly class CannedResponseRenderData
{
    public function __construct(
        public int $conversationId,
    ) {}
}
