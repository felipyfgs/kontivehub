<?php

namespace Database\Factories;

use App\Enums\Communication\ConversationBulkAction;
use App\Enums\Communication\ConversationBulkOperationStatus;
use App\Enums\TenantAccessMode;
use App\Models\CommunicationConversationBulkOperation;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommunicationConversationBulkOperation>
 */
class CommunicationConversationBulkOperationFactory extends Factory
{
    protected $model = CommunicationConversationBulkOperation::class;

    public function definition(): array
    {
        $membership = TenantMembership::factory()->create();

        return [
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $membership->tenant_id,
            'requested_by_user_id' => $membership->user_id,
            'requested_by_membership_id' => $membership->id,
            'access_mode' => TenantAccessMode::Membership,
            'idempotency_key' => 'idem-'.Str::lower((string) Str::ulid()),
            'payload_digest' => hash('sha256', (string) Str::uuid()),
            'action' => ConversationBulkAction::SetStatus,
            'params' => ['status' => 'RESOLVED'],
            'status' => ConversationBulkOperationStatus::Queued,
            'item_count' => 0,
            'succeeded_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'queued_at' => now(),
        ];
    }
}
