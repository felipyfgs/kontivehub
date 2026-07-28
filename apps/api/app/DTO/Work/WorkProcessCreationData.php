<?php

namespace App\DTO\Work;

final readonly class WorkProcessCreationData
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $tasks
     */
    public function __construct(
        public array $attributes,
        public array $tasks,
    ) {}
}
