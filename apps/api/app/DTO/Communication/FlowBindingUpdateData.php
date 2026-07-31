<?php

namespace App\DTO\Communication;

final readonly class FlowBindingUpdateData
{
    public function __construct(
        public int $lockVersion,
        public ?int $publishedVersionId,
        public bool $hasPublishedVersionId,
        public ?bool $enabled,
    ) {}
}
