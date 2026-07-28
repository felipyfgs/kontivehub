<?php

namespace App\DTO\Serpro;

use App\Enums\SerproEnvironment;
use Carbon\CarbonImmutable;

final readonly class SerproRolloutCreationData
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $action,
        public string $subjectType,
        public ?int $subjectId,
        public string $reason,
        public ?SerproEnvironment $environment,
        public ?int $tenantId,
        public array $context,
        public int $ttlHours,
        public ?CarbonImmutable $changeWindowStart,
        public ?CarbonImmutable $changeWindowEnd,
    ) {}
}
