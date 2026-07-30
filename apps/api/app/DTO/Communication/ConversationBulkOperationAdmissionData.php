<?php

namespace App\DTO\Communication;

use App\Enums\Communication\ConversationBulkAction;
use App\Models\User;

final readonly class ConversationBulkOperationAdmissionData
{
    /**
     * @param  array<string, mixed>  $params
     * @param  list<array{
     *     conversation_id: int,
     *     lock_version?: int|null,
     *     through_message_id?: int|null,
     *     read_state_version?: int|null
     * }>  $items
     */
    public function __construct(
        public User $actor,
        public ConversationBulkAction $action,
        public array $params,
        public array $items,
        public string $idempotencyKey,
    ) {}
}
