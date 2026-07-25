<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\Office;
use App\Services\Communication\Migrations\RichContentBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommunicationRichContentBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_office_scoped_resumable_and_has_no_business_side_effects(): void
    {
        [$office, $inbox, $conversation, $identity] = $this->context('session-backfill-office-0001');
        [$foreignOffice, $foreignInbox, $foreignConversation, $foreignIdentity] = $this->context('session-backfill-office-0002');
        $first = $this->message($office, $inbox, $conversation, $identity, [
            'location' => ['latitude' => -23.5, 'longitude' => -46.6, 'live' => false],
        ], MessageKind::Location);
        $second = $this->message($office, $inbox, $conversation, $identity, [
            'contact' => ['display_name' => 'Cliente', 'vcard' => 'BEGIN:VCARD'],
            'interactive_response' => ['selected_id' => 'yes', 'text' => 'Sim'],
        ], MessageKind::Contact);
        $foreign = $this->message($foreignOffice, $foreignInbox, $foreignConversation, $foreignIdentity, [
            'poll' => ['name' => 'Escolha', 'options' => ['A'], 'selectable_options' => 1],
        ], MessageKind::Poll);
        $eventsBefore = \DB::table('communication_events')->count();
        $outboxBefore = \DB::table('communication_outbox_entries')->count();

        $partial = app(RichContentBackfill::class)->run((int) $office->id, 0, 1, 1);
        $this->assertSame(1, $partial['scanned']);
        $this->assertNotNull($first->refresh()->content_encrypted);
        $this->assertArrayNotHasKey('location', $first->metadata ?? []);
        $this->assertNull($second->refresh()->content_encrypted);

        $resumed = app(RichContentBackfill::class)->run((int) $office->id, $partial['last_id'], 1);
        $this->assertSame(1, $resumed['scanned']);
        $this->assertSame(
            [['display_name' => 'Cliente', 'vcard' => 'BEGIN:VCARD']],
            $second->refresh()->content_encrypted['contacts'],
        );
        $this->assertSame('LEGACY_RESPONSE', $second->content_encrypted['interactive']['mode']);
        $this->assertArrayNotHasKey('contact', $second->metadata ?? []);
        $this->assertArrayNotHasKey('interactive_response', $second->metadata ?? []);
        $this->assertNull($foreign->refresh()->content_encrypted);
        $this->assertSame($eventsBefore, \DB::table('communication_events')->count());
        $this->assertSame($outboxBefore, \DB::table('communication_outbox_entries')->count());
        $this->assertSame(ConversationStatus::Open, $conversation->refresh()->status);
    }

    public function test_backfill_conflict_preserves_both_representations(): void
    {
        [$office, $inbox, $conversation, $identity] = $this->context('session-backfill-conflict-0001');
        $message = $this->message($office, $inbox, $conversation, $identity, [
            'location' => ['latitude' => -23.5, 'longitude' => -46.6, 'live' => false],
        ], MessageKind::Location);
        $message->forceFill([
            'content_encrypted' => ['location' => ['latitude' => -10, 'longitude' => -40, 'live' => false]],
        ])->saveQuietly();

        $result = app(RichContentBackfill::class)->run((int) $office->id);

        $this->assertSame(1, $result['conflicts']);
        $this->assertArrayHasKey('location', $message->refresh()->metadata);
        $this->assertSame(-10, $message->content_encrypted['location']['latitude']);
    }

    /** @return array{Office,CommunicationInbox,CommunicationConversation,CommunicationIdentity} */
    private function context(string $session): array
    {
        $office = Office::factory()->create();
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => 'Atendimento',
            'session_id' => $session,
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'is_active' => true,
        ]);
        $address = '+55119'.str_pad((string) $office->id, 7, '0', STR_PAD_LEFT);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);

        return [$office, $inbox, $conversation, $identity];
    }

    /** @param array<string,mixed> $metadata */
    private function message(
        Office $office,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        CommunicationIdentity $identity,
        array $metadata,
        MessageKind $kind,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'direction' => MessageDirection::Inbound,
            'kind' => $kind,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
