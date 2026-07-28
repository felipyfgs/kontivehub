<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class PublishDeclarationCalendarData
{
    /**
     * @param  list<array<string, mixed>>  $rules
     */
    public function __construct(
        public string $code,
        public string $label,
        public array $rules,
        public ?string $sourceRef,
        public ?string $notes,
        public string $timezone,
        public bool $recalculateOpen,
    ) {}
}
