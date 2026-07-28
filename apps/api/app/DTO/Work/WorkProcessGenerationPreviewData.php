<?php

namespace App\DTO\Work;

final readonly class WorkProcessGenerationPreviewData
{
    /**
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public string $competence,
        public array $selection,
        public array $overrides,
        public ?string $idempotencyKey,
    ) {}
}
