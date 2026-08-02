<?php

namespace App\DTO\Communication;

use App\Models\CommunicationConversation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ConversationListPageData
{
    /** @param LengthAwarePaginator<int, CommunicationConversation> $paginator */
    public function __construct(
        public LengthAwarePaginator $paginator,
        public ?string $snapshotToken = null,
        public ?string $snapshotExpiresAt = null,
    ) {}
}
