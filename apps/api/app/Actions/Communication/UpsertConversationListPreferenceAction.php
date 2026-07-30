<?php

namespace App\Actions\Communication;

use App\DTO\Communication\ConversationListPreferenceData;
use App\Models\CommunicationConversationListPreference;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class UpsertConversationListPreferenceAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
    ) {}

    public function handle(User $actor, ConversationListPreferenceData $data): CommunicationConversationListPreference
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $timestamp = now();

        DB::table('communication_conversation_list_preferences')->upsert(
            [[
                'tenant_id' => $tenantId,
                'user_id' => $actor->id,
                'status' => $data->status,
                'sort_by' => $data->sortBy->value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]],
            ['tenant_id', 'user_id'],
            ['status', 'sort_by', 'updated_at'],
        );

        return CommunicationConversationListPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->firstOrFail();
    }
}
