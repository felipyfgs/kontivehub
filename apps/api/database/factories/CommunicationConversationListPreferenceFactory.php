<?php

namespace Database\Factories;

use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Models\CommunicationConversationListPreference;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationConversationListPreference>
 */
class CommunicationConversationListPreferenceFactory extends Factory
{
    protected $model = CommunicationConversationListPreference::class;

    public function definition(): array
    {
        $membership = TenantMembership::factory()->create();

        return [
            'tenant_id' => $membership->tenant_id,
            'user_id' => $membership->user_id,
            'status' => ConversationStatus::Open->value,
            'sort_by' => ConversationListSort::defaultPreference()->value,
        ];
    }
}
