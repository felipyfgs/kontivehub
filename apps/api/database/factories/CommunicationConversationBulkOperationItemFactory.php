<?php

namespace Database\Factories;

use App\Enums\Communication\ConversationBulkItemStatus;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationBulkOperation;
use App\Models\CommunicationConversationBulkOperationItem;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommunicationConversationBulkOperationItem>
 */
class CommunicationConversationBulkOperationItemFactory extends Factory
{
    protected $model = CommunicationConversationBulkOperationItem::class;

    public function configure(): static
    {
        return $this->afterMaking(function (CommunicationConversationBulkOperationItem $item): void {
            $operation = CommunicationConversationBulkOperation::query()
                ->withoutGlobalScopes()
                ->find($item->bulk_operation_id);
            if ($operation !== null) {
                $item->tenant_id = $operation->tenant_id;
            }
        })->afterCreating(function (CommunicationConversationBulkOperationItem $item): void {
            CommunicationConversationBulkOperation::query()
                ->withoutGlobalScopes()
                ->whereKey($item->bulk_operation_id)
                ->update(['item_count' => CommunicationConversationBulkOperationItem::query()
                    ->withoutGlobalScopes()
                    ->where('bulk_operation_id', $item->bulk_operation_id)
                    ->count()]);
        });
    }

    public function definition(): array
    {
        $operation = CommunicationConversationBulkOperation::factory()->create();
        $tenantId = (int) $operation->tenant_id;
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'name' => fake()->words(2, true),
            'session_id' => 'session-'.Str::lower((string) Str::ulid()),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
        $address = '+5511'.fake()->numerify('#########');
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'name' => fake()->name(),
            'is_provisional' => false,
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
            'lock_version' => 1,
        ]);

        return [
            'tenant_id' => $tenantId,
            'bulk_operation_id' => $operation->id,
            'item_index' => 0,
            'conversation_id' => $conversation->id,
            'live_conversation_id' => $conversation->id,
            'resolved_conversation_id' => null,
            'inbox_id' => $inbox->id,
            'live_inbox_id' => $inbox->id,
            'lock_version' => 1,
            'through_message_id' => null,
            'read_state_version' => null,
            'status' => ConversationBulkItemStatus::Queued,
            'attempts' => 0,
        ];
    }
}
