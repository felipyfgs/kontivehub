<?php

namespace App\DTO\Communication;

use App\Enums\Communication\ConversationListSort;

final readonly class ConversationListPreferenceData
{
    public function __construct(
        public string $status,
        public ConversationListSort $sortBy,
    ) {}
}
