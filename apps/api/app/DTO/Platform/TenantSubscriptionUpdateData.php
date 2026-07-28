<?php

namespace App\DTO\Platform;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;

final readonly class TenantSubscriptionUpdateData
{
    public function __construct(
        public bool $hasStatus,
        public ?SubscriptionStatus $status,
        public bool $hasPlan,
        public ?SubscriptionPlan $plan,
        public bool $hasNotes,
        public ?string $notes,
        public bool $hasNegotiatedClientLimit,
        public ?int $negotiatedClientLimit,
    ) {}
}
