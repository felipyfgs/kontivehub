<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Enums\TenantRole;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Media\MediaStore;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationMessageBatchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('communication_media');
        $this->app->forgetInstance(MediaStore::class);
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.media.disk' => 'communication_media',
            'communication.outbound_builders.gif' => true,
            'communication.outbound_builders.media_batch' => true,
            'communication.outbound_features.gif' => true,
            'communication.outbound_features.media_batch' => true,
        ]);
    }

    public function test_batch_is_ordered_transactional_and_idempotent(): void
    {
        $conversation = $this->context();
        $url = '/api/v1/communication/conversations/'.$conversation->id.'/message-batches';

        $created = $this->post($url, $this->payload('batch-ordered-0001'), ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.client_batch_id', 'batch-ordered-0001')
            ->assertJsonPath('data.item_count', 2)
            ->assertJsonPath('data.messages.0.body', 'Primeiro')
            ->assertJsonPath('data.messages.1.body', 'Segundo');
        $messageIds = $created->json('data.messages.*.id');
        $this->assertCount(2, $messageIds);
        $this->assertDatabaseHas('communication_messages', [
            'id' => $messageIds[0],
            'batch_position' => 0,
        ]);
        $this->assertDatabaseHas('communication_messages', [
            'id' => $messageIds[1],
            'batch_position' => 1,
        ]);
        $this->assertDatabaseCount('communication_message_batches', 1);
        $this->assertDatabaseCount('communication_messages', 2);
        $this->assertDatabaseCount('communication_attachments', 2);
        $this->assertDatabaseCount('communication_outbox_entries', 1);
        $this->assertDatabaseHas('communication_attachments', [
            'sha256' => hash('sha256', '%PDF-primeiro'),
        ]);

        $this->post($url, $this->payload('batch-ordered-0001'), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.messages.0.id', $messageIds[0])
            ->assertJsonPath('data.messages.1.id', $messageIds[1]);
        $this->assertDatabaseCount('communication_message_batches', 1);
        $this->assertDatabaseCount('communication_messages', 2);
        $this->assertDatabaseCount('communication_attachments', 2);
        $this->assertDatabaseCount('communication_outbox_entries', 1);

        $conflict = $this->payload('batch-ordered-0001');
        $conflict['items'][1]['caption'] = 'Conteúdo divergente';
        $this->post($url, $conflict, ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('communication_messages', 2);
    }

    public function test_invalid_later_item_rolls_back_messages_outbox_and_private_objects(): void
    {
        $conversation = $this->context();
        $payload = $this->payload('batch-rollback-0001');
        $payload['items'][1]['gif'] = true;
        $payload['items'][1]['kind'] = 'DOCUMENT';

        $this->post(
            '/api/v1/communication/conversations/'.$conversation->id.'/message-batches',
            $payload,
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $this->assertDatabaseCount('communication_message_batches', 0);
        $this->assertDatabaseCount('communication_messages', 0);
        $this->assertDatabaseCount('communication_attachments', 0);
        $this->assertDatabaseCount('communication_outbox_entries', 0);
        $this->assertSame([], Storage::disk('communication_media')->allFiles());
    }

    public function test_batch_requires_reply_permission(): void
    {
        $conversation = $this->context('viewer');

        $this->post(
            '/api/v1/communication/conversations/'.$conversation->id.'/message-batches',
            $this->payload('batch-forbidden-0001'),
            ['Accept' => 'application/json'],
        )->assertForbidden();

        $this->assertDatabaseCount('communication_message_batches', 0);
        $this->assertDatabaseCount('communication_messages', 0);
    }

    public function test_forged_mime_is_rejected_before_private_storage(): void
    {
        $conversation = $this->context();
        $payload = $this->payload('batch-forged-mime-0001');
        $payload['items'][0]['file'] = UploadedFile::fake()
            ->createWithContent('forjado.pdf', "MZ\0\0executavel")
            ->mimeType('application/pdf');

        $this->post(
            '/api/v1/communication/conversations/'.$conversation->id.'/message-batches',
            $payload,
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('communication_message_batches', 0);
        $this->assertDatabaseCount('communication_messages', 0);
        $this->assertSame([], Storage::disk('communication_media')->allFiles());
    }

    /** @return array<string, mixed> */
    private function payload(string $clientBatchId): array
    {
        return [
            'client_batch_id' => $clientBatchId,
            'items' => [
                [
                    'kind' => 'DOCUMENT',
                    'caption' => 'Primeiro',
                    'file' => UploadedFile::fake()->createWithContent('primeiro.pdf', '%PDF-primeiro'),
                ],
                [
                    'kind' => 'DOCUMENT',
                    'caption' => 'Segundo',
                    'file' => UploadedFile::fake()->createWithContent('segundo.pdf', '%PDF-segundo'),
                ],
            ],
        ];
    }

    private function context(string $permissionProfile = 'operator'): CommunicationConversation
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser, $permissionProfile)->create();
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Batch',
            'session_id' => 'session-'.Str::ulid(),
            'address_encrypted' => '+5511000000066',
            'address_hash' => hash('sha256', '+5511000000066'),
            'address_masked' => '***0066',
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
        $membership = TenantMembership::query()->withoutGlobalScopes()->where('user_id', $user->id)->sole();
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'tenant_membership_id' => $membership->id,
            'is_active' => true,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Destino',
            'is_provisional' => false,
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => '+5511999990066',
            'address_hash' => hash('sha256', '+5511999990066'),
            'address_masked' => '***0066',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();

        return $conversation;
    }
}
