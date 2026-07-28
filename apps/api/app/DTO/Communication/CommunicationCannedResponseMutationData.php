<?php

namespace App\DTO\Communication;

final readonly class CommunicationCannedResponseMutationData
{
    public function __construct(
        public string $title,
        public string $shortcut,
        public string $body,
        public ?bool $isActive,
        public ?int $lockVersion,
    ) {}
}
