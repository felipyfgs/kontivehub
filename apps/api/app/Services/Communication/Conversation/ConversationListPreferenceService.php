<?php

namespace App\Services\Communication\Conversation;

use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Models\CommunicationConversationListPreference;
use App\Models\User;
use App\Support\CurrentTenant;

final readonly class ConversationListPreferenceService
{
    public function __construct(
        private CurrentTenant $currentTenant,
    ) {}

    /**
     * @return array{status: string, sort_by: string, is_default: bool}
     */
    public function resolve(User $actor): array
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $preference = CommunicationConversationListPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->first();

        if ($preference === null) {
            return [
                'status' => ConversationStatus::Open->value,
                'sort_by' => ConversationListSort::defaultPreference()->value,
                'is_default' => true,
            ];
        }

        return [
            'status' => (string) $preference->status,
            'sort_by' => $preference->sort_by->value,
            'is_default' => false,
        ];
    }
}
