<?php

namespace Database\Factories;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessageBatch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CommunicationMessageBatch> */
final class CommunicationMessageBatchFactory extends Factory
{
    protected $model = CommunicationMessageBatch::class;

    public function definition(): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => fake()->words(2, true),
            'session_id' => 'session-'.Str::ulid(),
            'address_encrypted' => '+5511000000099',
            'address_hash' => hash('sha256', '+5511000000099'),
            'address_masked' => '***0099',
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => fake()->name(),
            'is_provisional' => false,
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => '+5511999990099',
            'address_hash' => hash('sha256', '+5511999990099'),
            'address_masked' => '***0099',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);

        return [
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'client_batch_id' => 'batch-'.Str::lower((string) Str::ulid()),
            'request_digest' => hash('sha256', (string) Str::uuid()),
            'status' => 'QUEUED',
            'item_count' => 2,
        ];
    }
}
