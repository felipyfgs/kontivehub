<?php

namespace App\DTO\Communication;

final readonly class CommunicationFlowBindingCreationData
{
    public function __construct(
        public int $inboxId,
        public ?int $publishedVersionId,
        public bool $enabled,
    ) {}
}
