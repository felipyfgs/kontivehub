<?php

namespace Tests\Feature;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationChannel;
use App\Models\CommunicationAutomationPolicy;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Support\CurrentOffice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class CommunicationModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_and_sensitive_values_are_casted(): void
    {
        $this->assertTrue(Schema::hasColumns('communication_inboxes', [
            'office_id',
            'session_id',
            'status',
            'is_enabled',
            'is_default',
        ]));
        $this->assertFalse(Schema::hasColumn('communication_inboxes', 'deleted_at'));
        $this->assertTrue(Schema::hasColumns('communication_messages', [
            'body_encrypted',
            'provider_message_id',
            'gateway_event_id',
        ]));
        $this->assertTrue(Schema::hasColumns('client_communication_dispatches', [
            'inbox_id',
            'identity_id',
            'message_id',
            'artifact_digest',
            'scheduled_at',
            'skipped_at',
        ]));

        $office = Office::factory()->create();
        $inbox = CommunicationInbox::query()->create([
            'office_id' => $office->id,
            'name' => 'Geral',
            'session_id' => 'session-model-0001',
            'address_encrypted' => '+5511999991234',
            'address_hash' => hash('sha256', '+5511999991234'),
            'address_masked' => '+55•••••1234',
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $policy = CommunicationAutomationPolicy::query()->create([
            'office_id' => $office->id,
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'inbox_id' => $inbox->id,
            'recipient_mode' => RecipientMode::AllEligible,
            'template_key' => 'pgdasd.das.available',
        ]);

        $this->assertSame(InboxStatus::Connected, $inbox->fresh()->status);
        $this->assertSame('+5511999991234', $inbox->fresh()->address_encrypted);
        $this->assertNotSame('+5511999991234', $inbox->fresh()->getRawOriginal('address_encrypted'));
        $this->assertSame(RecipientMode::AllEligible, $policy->fresh()->recipient_mode);
        $this->assertFalse($policy->fresh()->is_enabled);
    }

    public function test_inbox_status_contract_has_only_three_canonical_values_and_normalizes_legacy_values(): void
    {
        $this->assertSame(
            ['DISCONNECTED', 'CONNECTING', 'CONNECTED'],
            array_map(static fn (InboxStatus $status): string => $status->value, InboxStatus::cases()),
        );
        $this->assertSame(InboxStatus::Connecting, InboxStatus::normalize('PAIRING'));
        foreach (['DISABLED', 'PROVISIONED', 'DEGRADED', 'REVOKED', 'LOGGED_OUT'] as $legacy) {
            $this->assertSame(InboxStatus::Disconnected, InboxStatus::normalize($legacy));
        }

        $office = Office::factory()->create();
        $inbox = CommunicationInbox::query()->create([
            'office_id' => $office->id,
            'name' => 'Sessão sem estado explícito',
            'session_id' => 'session-default-status-0001',
        ]);

        $this->assertSame(InboxStatus::Disconnected, $inbox->fresh()->status);
    }

    public function test_status_migration_converts_pairing_and_all_other_legacy_values(): void
    {
        $office = Office::factory()->create();
        $now = now();
        DB::table('communication_inboxes')->insert([
            [
                'office_id' => $office->id,
                'name' => 'Legada pairing',
                'session_id' => 'session-legacy-pairing-0001',
                'status' => 'PAIRING',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'office_id' => $office->id,
                'name' => 'Legada revoked',
                'session_id' => 'session-legacy-revoked-0001',
                'status' => 'REVOKED',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'office_id' => $office->id,
                'name' => 'Canônica connected',
                'session_id' => 'session-canonical-connected-0001',
                'status' => 'CONNECTED',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $migration = require database_path('migrations/2026_07_22_151000_simplify_communication_inbox_statuses.php');
        $migration->up();

        $this->assertDatabaseHas('communication_inboxes', [
            'session_id' => 'session-legacy-pairing-0001',
            'status' => InboxStatus::Connecting->value,
        ]);
        $this->assertDatabaseHas('communication_inboxes', [
            'session_id' => 'session-legacy-revoked-0001',
            'status' => InboxStatus::Disconnected->value,
        ]);
        $this->assertDatabaseHas('communication_inboxes', [
            'session_id' => 'session-canonical-connected-0001',
            'status' => InboxStatus::Connected->value,
        ]);
    }

    public function test_identity_is_unique_inside_office_but_can_repeat_in_another_office(): void
    {
        $firstOffice = Office::factory()->create();
        $secondOffice = Office::factory()->create();
        $hash = hash('sha256', '+5511999991234');

        $this->createIdentity($firstOffice, $hash);
        $this->createIdentity($secondOffice, $hash);

        $this->expectException(QueryException::class);
        $this->createIdentity($firstOffice, $hash);
    }

    public function test_only_one_default_inbox_and_one_active_conversation_are_allowed(): void
    {
        $office = Office::factory()->create();
        $inbox = $this->createInbox($office, 'first-session', true);
        $identity = $this->createIdentity($office, hash('sha256', '+5511999990001'));

        CommunicationConversation::query()->create([
            'office_id' => $office->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Resolved,
        ]);
        CommunicationConversation::query()->create([
            'office_id' => $office->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
        ]);

        try {
            CommunicationConversation::query()->create([
                'office_id' => $office->id,
                'inbox_id' => $inbox->id,
                'identity_id' => $identity->id,
                'status' => ConversationStatus::Pending,
            ]);
            $this->fail('A segunda conversa ativa deveria violar a constraint.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        $this->createInbox($office, 'second-session', true);
    }

    public function test_global_scope_isolates_inboxes_by_current_office(): void
    {
        config()->set('fiscal_data_model.fail_closed_scopes', true);
        $firstOffice = Office::factory()->create();
        $secondOffice = Office::factory()->create();
        $first = $this->createInbox($firstOffice, 'scope-session-1');
        $this->createInbox($secondOffice, 'scope-session-2');
        $membership = OfficeMembership::factory()->create(['office_id' => $firstOffice->id]);

        app(CurrentOffice::class)->bind($membership->user, $membership->load('office'));

        $this->assertSame([$first->id], CommunicationInbox::query()->pluck('id')->all());
    }

    public function test_communication_event_is_append_only(): void
    {
        $office = Office::factory()->create();
        $event = CommunicationEvent::query()->create([
            'office_id' => $office->id,
            'type' => 'CONVERSATION_CREATED',
            'payload_digest' => hash('sha256', '{}'),
            'payload' => ['status' => 'OPEN'],
            'occurred_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->update(['type' => 'CHANGED']);
    }

    private function createInbox(Office $office, string $sessionId, bool $default = false): CommunicationInbox
    {
        return CommunicationInbox::query()->create([
            'office_id' => $office->id,
            'name' => 'Inbox '.$sessionId,
            'session_id' => $sessionId,
            'status' => InboxStatus::Disconnected,
            'is_enabled' => false,
            'is_default' => $default,
        ]);
    }

    private function createIdentity(Office $office, string $hash): CommunicationIdentity
    {
        $contact = CommunicationContact::query()->create([
            'office_id' => $office->id,
            'is_provisional' => true,
            'is_active' => true,
        ]);

        return CommunicationIdentity::query()->create([
            'office_id' => $office->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => '+5511999991234',
            'address_hash' => $hash,
            'address_masked' => '+55•••••1234',
            'is_active' => true,
        ]);
    }
}
