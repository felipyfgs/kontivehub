<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationBulkItemStatus;
use App\Enums\Communication\ConversationBulkOperationStatus;
use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationChannel;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Jobs\Communication\ProcessConversationBulkOperationJob;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationBulkOperation;
use App\Models\CommunicationConversationBulkOperationItem;
use App\Models\CommunicationConversationListPreference;
use App\Models\CommunicationConversationUnreadMessage;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationLabel;
use App\Models\CommunicationMessage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Authorization\SystemTenantPermissionProfiles;
use App\Services\Communication\Conversation\ConversationBulkOperationProcessor;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationConversationListOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([CommunicationEventCommitted::class]);
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
        ]);
    }

    public function test_label_ids_filter_uses_or_semantics_and_isolates_tenants(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreign = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);

        $labelA = $this->label($tenant, 'A');
        $labelB = $this->label($tenant, 'B');
        $foreignLabel = $this->label($foreign, 'X');

        $withA = $this->conversation($tenant, $inbox, '+5511999900001');
        $withB = $this->conversation($tenant, $inbox, '+5511999900002');
        $withBoth = $this->conversation($tenant, $inbox, '+5511999900003');
        $withNone = $this->conversation($tenant, $inbox, '+5511999900004');
        $withA->labels()->attach($labelA->id, [
            'tenant_id' => $tenant->id,
            'assigned_by_membership_id' => null,
        ]);
        $withB->labels()->attach($labelB->id, [
            'tenant_id' => $tenant->id,
            'assigned_by_membership_id' => null,
        ]);
        $withBoth->labels()->attach([
            $labelA->id => ['tenant_id' => $tenant->id, 'assigned_by_membership_id' => null],
            $labelB->id => ['tenant_id' => $tenant->id, 'assigned_by_membership_id' => null],
        ]);

        $this->authenticate($operator);
        $response = $this->getJson(
            '/api/v1/communication/conversations?label_ids[]='.$labelA->id.'&label_ids[]='.$labelB->id
        );
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($withA->id, $ids);
        $this->assertContains($withB->id, $ids);
        $this->assertContains($withBoth->id, $ids);
        $this->assertNotContains($withNone->id, $ids);

        $this->getJson('/api/v1/communication/conversations?label_ids[]='.$foreignLabel->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_sort_by_is_stable_and_default_preserved_when_absent(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);

        $older = $this->conversation($tenant, $inbox, '+5511999900010');
        $newer = $this->conversation($tenant, $inbox, '+5511999900011');
        $older->forceFill([
            'priority' => 5,
            'last_message_at' => now()->subHour(),
        ])->save();
        $newer->forceFill([
            'priority' => 1,
            'last_message_at' => now(),
        ])->save();

        $this->authenticate($operator);

        $default = $this->getJson('/api/v1/communication/conversations')->assertOk();
        $defaultIds = collect($default->json('data'))->pluck('id')->all();
        $this->assertSame([$older->id, $newer->id], $defaultIds);

        $byActivity = $this->getJson('/api/v1/communication/conversations?sort_by=last_activity_desc')
            ->assertOk();
        $activityIds = collect($byActivity->json('data'))->pluck('id')->all();
        $this->assertSame([$newer->id, $older->id], $activityIds);

        $tieA = $this->conversation($tenant, $inbox, '+5511999900012');
        $tieB = $this->conversation($tenant, $inbox, '+5511999900013');
        $stamp = now()->startOfSecond();
        $tieA->forceFill(['last_message_at' => $stamp, 'priority' => 0])->save();
        $tieB->forceFill(['last_message_at' => $stamp, 'priority' => 0])->save();

        $stable = $this->getJson('/api/v1/communication/conversations?sort_by=last_activity_desc')
            ->assertOk();
        $stableIds = collect($stable->json('data'))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $tiePositions = array_values(array_filter(
            $stableIds,
            static fn (int $id): bool => in_array($id, [(int) $tieA->id, (int) $tieB->id], true),
        ));
        $expected = [(int) $tieB->id, (int) $tieA->id];
        if ((int) $tieA->id > (int) $tieB->id) {
            $expected = [(int) $tieA->id, (int) $tieB->id];
        }
        $this->assertSame($expected, $tiePositions);
    }

    public function test_sort_by_unread_desc_orders_by_live_unread_ledger_without_ambiguous_column(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);

        $none = $this->conversation($tenant, $inbox, '+5511999900101');
        $one = $this->conversation($tenant, $inbox, '+5511999900102');
        $two = $this->conversation($tenant, $inbox, '+5511999900103');
        $none->forceFill(['last_message_at' => now()->subMinutes(1)])->save();
        $one->forceFill(['last_message_at' => now()->subMinutes(2)])->save();
        $two->forceFill(['last_message_at' => now()->subMinutes(3)])->save();

        $this->seedUnread($one, $this->message($tenant, $inbox, $one, 'uma'));
        $this->seedUnread($two, $this->message($tenant, $inbox, $two, 'duas-a'));
        $this->seedUnread($two, $this->message($tenant, $inbox, $two, 'duas-b'));

        $this->authenticate($operator);
        $response = $this->getJson('/api/v1/communication/conversations?status=OPEN&sort_by=unread_desc')
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $this->assertSame([(int) $two->id, (int) $one->id, (int) $none->id], $ids);
        $byId = collect($response->json('data'))->keyBy(static fn (array $row): int => (int) $row['id']);
        $this->assertSame(2, (int) $byId[(int) $two->id]['unread_count']);
        $this->assertSame(1, (int) $byId[(int) $one->id]['unread_count']);
        $this->assertSame(0, (int) $byId[(int) $none->id]['unread_count']);
    }

    public function test_all_allowlisted_sort_by_values_execute_without_sql_error(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);

        $a = $this->conversation($tenant, $inbox, '+5511999900201');
        $b = $this->conversation($tenant, $inbox, '+5511999900202');
        $a->forceFill([
            'priority' => 2,
            'last_message_at' => now()->subHour(),
            'created_at' => now()->subDay(),
        ])->save();
        $b->forceFill([
            'priority' => 9,
            'last_message_at' => now(),
            'created_at' => now(),
        ])->save();
        $this->seedUnread($a, $this->message($tenant, $inbox, $a, 'unread-a'));

        $this->authenticate($operator);

        foreach ([
            'last_activity_desc',
            'last_activity_asc',
            'created_desc',
            'created_asc',
            'unread_desc',
            'priority_desc',
            'priority_asc',
        ] as $sortBy) {
            $this->getJson('/api/v1/communication/conversations?status=OPEN&sort_by='.$sortBy)
                ->assertOk()
                ->assertJsonCount(2, 'data');
        }

        $this->getJson('/api/v1/communication/conversations?status=OPEN&sort_by=not_a_sort')
            ->assertUnprocessable();

        $priority = $this->getJson('/api/v1/communication/conversations?status=OPEN&sort_by=priority_desc')
            ->assertOk();
        $priorityIds = collect($priority->json('data'))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertSame([(int) $b->id, (int) $a->id], $priorityIds);

        $activity = $this->getJson('/api/v1/communication/conversations?status=OPEN&sort_by=last_activity_desc')
            ->assertOk();
        $activityIds = collect($activity->json('data'))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertSame([(int) $b->id, (int) $a->id], $activityIds);
    }

    public function test_preferences_defaults_put_and_cross_tenant_independence(): void
    {
        $tenantA = Tenant::factory()->create(['communication_enabled' => true]);
        $tenantB = Tenant::factory()->create(['communication_enabled' => true]);
        $user = User::factory()->forTenant($tenantA, TenantRole::TenantUser)->create();
        $profilesB = app(SystemTenantPermissionProfiles::class)->ensure($tenantB);
        $tenantB->users()->attach($user->id, [
            'role' => TenantRole::TenantUser->value,
            'permission_profile_id' => $profilesB['operator']->id,
            'is_active' => true,
        ]);
        $inboxA = $this->inbox($tenantA, 'A');
        $inboxB = $this->inbox($tenantB, 'B');
        $this->member($inboxA, $user);
        $this->member($inboxB, $user);

        $user->forceFill(['selected_tenant_id' => $tenantA->id])->save();
        $this->authenticate($user);

        $this->getJson('/api/v1/communication/conversation-list-preferences')
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.sort_by', 'last_activity_desc')
            ->assertJsonPath('data.is_default', true);

        $this->putJson('/api/v1/communication/conversation-list-preferences', [
            'status' => 'PENDING',
            'sort_by' => 'priority_desc',
            'tenant_id' => $tenantB->id,
        ])->assertUnprocessable();
        $this->putJson('/api/v1/communication/conversation-list-preferences', [
            'status' => 'PENDING',
            'sort_by' => 'priority_desc',
            'user_id' => User::factory()->create()->id,
        ])->assertUnprocessable();

        $this->putJson('/api/v1/communication/conversation-list-preferences', [
            'status' => 'PENDING',
            'sort_by' => 'priority_desc',
        ])->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.sort_by', 'priority_desc')
            ->assertJsonPath('data.is_default', false);

        $this->getJson('/api/v1/communication/conversation-list-preferences')
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.sort_by', 'priority_desc')
            ->assertJsonPath('data.is_default', false);

        $this->assertDatabaseHas('communication_conversation_list_preferences', [
            'tenant_id' => $tenantA->id,
            'user_id' => $user->id,
            'status' => 'PENDING',
            'sort_by' => 'priority_desc',
        ]);

        $user->forceFill(['selected_tenant_id' => $tenantB->id])->save();
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/communication/conversation-list-preferences')
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.sort_by', 'last_activity_desc')
            ->assertJsonPath('data.is_default', true);

        $this->putJson('/api/v1/communication/conversation-list-preferences', [
            'status' => 'ALL',
            'sort_by' => 'created_asc',
        ])->assertOk();

        $this->getJson('/api/v1/communication/conversation-list-preferences')
            ->assertOk()
            ->assertJsonPath('data.status', 'ALL')
            ->assertJsonPath('data.sort_by', 'created_asc')
            ->assertJsonPath('data.is_default', false);

        $this->assertSame(2, CommunicationConversationListPreference::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseHas('communication_conversation_list_preferences', [
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'status' => 'ALL',
            'sort_by' => 'created_asc',
        ]);
    }

    public function test_preferences_upsert_returns_the_single_row_after_an_existing_preference_is_updated(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $preference = CommunicationConversationListPreference::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'OPEN',
            'sort_by' => ConversationListSort::LastActivityDesc->value,
        ]);

        $this->authenticate($user);
        $response = $this->putJson('/api/v1/communication/conversation-list-preferences', [
            'status' => 'PENDING',
            'sort_by' => ConversationListSort::PriorityDesc->value,
        ])->assertOk();

        $this->assertSame(1, CommunicationConversationListPreference::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->count());
        $this->assertSame((int) $preference->id, (int) CommunicationConversationListPreference::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->value('id'));
        $this->assertSame('PENDING', $response->json('data.status'));
        $this->assertSame(ConversationListSort::PriorityDesc->value, $response->json('data.sort_by'));
    }

    public function test_bulk_create_is_idempotent_and_rejects_key_reuse(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999900020');

        $this->authenticate($operator);
        $payload = [
            'action' => 'SET_STATUS',
            'params' => ['status' => 'RESOLVED'],
            'items' => [[
                'conversation_id' => $conversation->id,
                'lock_version' => (int) $conversation->lock_version,
            ]],
        ];

        $first = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            $payload,
            ['Idempotency-Key' => 'bulk-key-0001'],
        )->assertStatus(202);
        $operationId = $first->json('data.id');
        $this->assertNotEmpty($operationId);
        Queue::assertPushed(ProcessConversationBulkOperationJob::class, 1);

        $retry = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            $payload,
            ['Idempotency-Key' => 'bulk-key-0001'],
        )->assertStatus(202);
        $this->assertSame($operationId, $retry->json('data.id'));
        Queue::assertPushed(ProcessConversationBulkOperationJob::class, 1);
        $this->assertSame(1, CommunicationConversationBulkOperation::query()->count());
        $this->assertSame(1, CommunicationConversationBulkOperationItem::query()->count());

        $otherOperator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $this->member($inbox, $otherOperator);
        $this->authenticate($otherOperator);
        $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            $payload,
            ['Idempotency-Key' => 'bulk-key-0001'],
        )->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        Queue::assertPushed(ProcessConversationBulkOperationJob::class, 1);

        $this->authenticate($operator);
        $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'SET_STATUS',
                'params' => ['status' => 'PENDING'],
                'items' => [[
                    'conversation_id' => $conversation->id,
                    'lock_version' => (int) $conversation->lock_version,
                ]],
            ],
            ['Idempotency-Key' => 'bulk-key-0001'],
        )->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_bulk_rejects_unauthorized_ids_without_creating_operation(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreign = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $foreignInbox = $this->inbox($foreign, 'Outro');
        $this->member($inbox, $operator);
        $local = $this->conversation($tenant, $inbox, '+5511999900030');
        $foreignConversation = $this->conversation($foreign, $foreignInbox, '+5511999900031');

        $this->authenticate($operator);
        $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'SET_STATUS',
                'params' => ['status' => 'RESOLVED'],
                'items' => [
                    [
                        'conversation_id' => $local->id,
                        'lock_version' => (int) $local->lock_version,
                    ],
                    [
                        'conversation_id' => $foreignConversation->id,
                        'lock_version' => 1,
                    ],
                ],
            ],
            ['Idempotency-Key' => 'bulk-key-unauthorized'],
        )->assertStatus(422)->assertJsonPath('code', 'CONVERSATION_BULK_INVALID_ITEMS');

        $this->assertSame(0, CommunicationConversationBulkOperation::query()->withoutGlobalScopes()->count());
    }

    public function test_bulk_process_set_status_supports_partial_success(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $ok = $this->conversation($tenant, $inbox, '+5511999900040');
        $stale = $this->conversation($tenant, $inbox, '+5511999900041');
        $stale->forceFill(['lock_version' => 3])->save();

        $this->authenticate($operator);
        $response = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'SET_STATUS',
                'params' => ['status' => 'RESOLVED'],
                'items' => [
                    [
                        'conversation_id' => $ok->id,
                        'lock_version' => (int) $ok->lock_version,
                    ],
                    [
                        'conversation_id' => $stale->id,
                        'lock_version' => 1,
                    ],
                ],
            ],
            ['Idempotency-Key' => 'bulk-key-partial'],
        )->assertStatus(202);

        $operation = CommunicationConversationBulkOperation::query()
            ->where('public_id', $response->json('data.id'))
            ->firstOrFail();

        app(ConversationBulkOperationProcessor::class)->process((int) $operation->id);

        $operation->refresh();
        $this->assertSame(ConversationBulkOperationStatus::CompletedWithErrors, $operation->status);
        $this->assertSame(1, (int) $operation->succeeded_count);
        $this->assertSame(1, (int) $operation->failed_count);
        $this->assertSame(ConversationStatus::Resolved, $ok->fresh()->status);
        $this->assertSame(ConversationStatus::Open, $stale->fresh()->status);

        $this->getJson('/api/v1/communication/conversation-bulk-operations/'.$operation->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED_WITH_ERRORS');

        $items = $this->getJson(
            '/api/v1/communication/conversation-bulk-operations/'.$operation->public_id.'/items?status=FAILED'
        )->assertOk();
        $this->assertCount(1, $items->json('data'));
        $this->assertSame('VERSION_CONFLICT', $items->json('data.0.result_code'));
    }

    public function test_bulk_preserves_and_fails_an_item_when_its_conversation_is_deleted(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $survivor = $this->conversation($tenant, $inbox, '+5511999900042');
        $deleted = $this->conversation($tenant, $inbox, '+5511999900043');

        $this->authenticate($operator);
        $response = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'SET_STATUS',
                'params' => ['status' => 'RESOLVED'],
                'items' => [
                    ['conversation_id' => $survivor->id, 'lock_version' => (int) $survivor->lock_version],
                    ['conversation_id' => $deleted->id, 'lock_version' => (int) $deleted->lock_version],
                ],
            ],
            ['Idempotency-Key' => 'bulk-key-concurrent-delete'],
        )->assertStatus(202);

        $operation = CommunicationConversationBulkOperation::query()
            ->where('public_id', $response->json('data.id'))
            ->firstOrFail();
        $deletedId = (int) $deleted->id;
        $deleted->delete();

        app(ConversationBulkOperationProcessor::class)->process((int) $operation->id);

        $operation->refresh();
        $missingItem = $operation->items()->where('conversation_id', $deletedId)->firstOrFail();
        $this->assertSame(ConversationBulkOperationStatus::CompletedWithErrors, $operation->status);
        $this->assertSame(2, (int) $operation->item_count);
        $this->assertSame(1, (int) $operation->succeeded_count);
        $this->assertSame(1, (int) $operation->failed_count);
        $this->assertSame(2, (int) $operation->succeeded_count + (int) $operation->failed_count);
        $this->assertSame(ConversationBulkItemStatus::Failed, $missingItem->status);
        $this->assertSame('CONVERSATION_NOT_FOUND', $missingItem->result_code);
        $this->assertNull($missingItem->live_conversation_id);
        $this->assertSame($deletedId, (int) $missingItem->conversation_id);
    }

    public function test_bulk_processing_item_prevents_premature_completion_without_requeue_loop(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+55119999000415');
        $this->authenticate($operator);

        $response = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'SET_STATUS',
                'params' => ['status' => 'RESOLVED'],
                'items' => [[
                    'conversation_id' => $conversation->id,
                    'lock_version' => (int) $conversation->lock_version,
                ]],
            ],
            ['Idempotency-Key' => 'bulk-key-processing-item'],
        )->assertStatus(202);
        $operation = CommunicationConversationBulkOperation::query()
            ->where('public_id', $response->json('data.id'))
            ->firstOrFail();
        $this->assertNotNull($operation->requested_by_membership_id);
        $this->assertTrue(
            TenantMembership::query()
                ->withoutGlobalScopes()
                ->whereKey($operation->requested_by_membership_id)
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $operator->id)
                ->where('is_active', true)
                ->exists(),
        );
        $operation->items()->firstOrFail()->forceFill([
            'status' => ConversationBulkItemStatus::Processing,
        ])->save();

        app(ConversationBulkOperationProcessor::class)->process((int) $operation->id);

        $operation->refresh();
        $this->assertSame(ConversationBulkOperationStatus::Running, $operation->status);
        $this->assertNull($operation->completed_at);
        $this->assertSame(ConversationBulkItemStatus::Processing, $operation->items()->firstOrFail()->status);
        Queue::assertPushed(ProcessConversationBulkOperationJob::class, 1);
    }

    public function test_bulk_label_mutation_rolls_back_the_whole_item_when_a_later_label_disappears(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999900042');
        $first = $this->label($tenant, 'Primeiro');
        $second = $this->label($tenant, 'Segundo');

        $this->authenticate($operator);
        $response = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'ADD_LABELS',
                'params' => ['label_ids' => [$first->id, $second->id]],
                'items' => [['conversation_id' => $conversation->id]],
            ],
            ['Idempotency-Key' => 'bulk-key-label-savepoint'],
        )->assertStatus(202);

        $operation = CommunicationConversationBulkOperation::query()
            ->where('public_id', $response->json('data.id'))
            ->firstOrFail();
        $second->delete();

        app(ConversationBulkOperationProcessor::class)->process((int) $operation->id);

        $operation->refresh();
        $this->assertSame(ConversationBulkOperationStatus::Failed, $operation->status);
        $this->assertSame(ConversationBulkItemStatus::Failed, $operation->items()->firstOrFail()->status);
        $this->assertSame([], $conversation->labels()->pluck('communication_labels.id')->all());
    }

    public function test_bulk_requires_nullable_assignment_params_to_be_explicit(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999900043');
        $this->authenticate($operator);

        foreach ([
            'SET_ASSIGNEE' => 'assignee_membership_id',
            'SET_DEPARTMENT' => 'work_department_id',
        ] as $action => $field) {
            $this->postJson(
                '/api/v1/communication/conversation-bulk-operations',
                [
                    'action' => $action,
                    'params' => [],
                    'items' => [[
                        'conversation_id' => $conversation->id,
                        'lock_version' => (int) $conversation->lock_version,
                    ]],
                ],
                ['Idempotency-Key' => 'bulk-key-missing-'.strtolower($action)],
            )->assertUnprocessable()->assertJsonValidationErrors("params.{$field}");
        }

        $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'SET_ASSIGNEE',
                'params' => ['assignee_membership_id' => null],
                'items' => [[
                    'conversation_id' => $conversation->id,
                    'lock_version' => (int) $conversation->lock_version,
                ]],
            ],
            ['Idempotency-Key' => 'bulk-key-explicit-null'],
        )->assertStatus(202);
    }

    public function test_bulk_label_idempotency_treats_label_order_as_a_set(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999900044');
        $first = $this->label($tenant, 'A');
        $second = $this->label($tenant, 'B');
        $this->authenticate($operator);

        $payload = fn (array $labelIds): array => [
            'action' => 'ADD_LABELS',
            'params' => ['label_ids' => $labelIds],
            'items' => [['conversation_id' => $conversation->id]],
        ];
        $firstResponse = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            $payload([$first->id, $second->id]),
            ['Idempotency-Key' => 'bulk-key-label-order'],
        )->assertStatus(202);
        $retry = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            $payload([$second->id, $first->id]),
            ['Idempotency-Key' => 'bulk-key-label-order'],
        )->assertStatus(202);

        $this->assertSame($firstResponse->json('data.id'), $retry->json('data.id'));
        Queue::assertPushed(ProcessConversationBulkOperationJob::class, 1);
    }

    public function test_bulk_does_not_rebind_an_operation_after_membership_recreation(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999900045');
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $operator->id)
            ->firstOrFail();
        $this->authenticate($operator);

        $response = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'SET_STATUS',
                'params' => ['status' => 'RESOLVED'],
                'items' => [[
                    'conversation_id' => $conversation->id,
                    'lock_version' => (int) $conversation->lock_version,
                ]],
            ],
            ['Idempotency-Key' => 'bulk-key-membership-recreated'],
        )->assertStatus(202);
        $operation = CommunicationConversationBulkOperation::query()
            ->where('public_id', $response->json('data.id'))
            ->firstOrFail();

        $membership->delete();
        TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $operator->id,
        ]);
        app(ConversationBulkOperationProcessor::class)->process((int) $operation->id);

        $operation->refresh();
        $this->assertSame(ConversationBulkOperationStatus::Failed, $operation->status);
        $this->assertSame('ACTOR_CONTEXT_UNAVAILABLE', $operation->error_code);
        $this->assertSame(ConversationStatus::Open, $conversation->fresh()->status);
    }

    public function test_bulk_factories_and_database_constraints_keep_tenant_graph_coherent(): void
    {
        $preference = CommunicationConversationListPreference::factory()->create();
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $preference->tenant_id,
            'user_id' => $preference->user_id,
        ]);

        $operation = CommunicationConversationBulkOperation::factory()->create();
        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $operation->requested_by_membership_id,
            'tenant_id' => $operation->tenant_id,
            'user_id' => $operation->requested_by_user_id,
        ]);

        $item = CommunicationConversationBulkOperationItem::factory()->create();
        $this->assertSame(
            (int) $item->tenant_id,
            (int) CommunicationConversationBulkOperation::query()->withoutGlobalScopes()
                ->findOrFail($item->bulk_operation_id)->tenant_id,
        );
        $this->assertSame(
            (int) $item->tenant_id,
            (int) CommunicationConversation::query()->withoutGlobalScopes()
                ->findOrFail($item->conversation_id)->tenant_id,
        );
        $this->assertSame(
            (int) $item->tenant_id,
            (int) CommunicationInbox::query()->withoutGlobalScopes()
                ->findOrFail($item->inbox_id)->tenant_id,
        );

        $foreign = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignInbox = $this->inbox($foreign, 'Outro tenant');
        $foreignConversation = $this->conversation($foreign, $foreignInbox, '+5511999900046');

        try {
            DB::transaction(static function () use ($item, $foreignConversation, $foreignInbox): void {
                CommunicationConversationBulkOperationItem::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $item->tenant_id,
                    'bulk_operation_id' => $item->bulk_operation_id,
                    'item_index' => 1,
                    'conversation_id' => $foreignConversation->id,
                    'live_conversation_id' => $foreignConversation->id,
                    'inbox_id' => $foreignInbox->id,
                    'live_inbox_id' => $foreignInbox->id,
                    'status' => ConversationBulkItemStatus::Queued,
                ]);
            });
            $this->fail('A FK composta aceitou relacionamentos de outro tenant.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_bulk_process_mark_read_is_local_only(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $conversation = $this->conversation($tenant, $inbox, '+5511999900050');
        $message = $this->message($tenant, $inbox, $conversation, 'inbound bulk');
        $this->seedUnread($conversation, $message);

        $this->authenticate($operator);
        $response = $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'action' => 'MARK_READ',
                'items' => [[
                    'conversation_id' => $conversation->id,
                    'through_message_id' => $message->id,
                ]],
            ],
            ['Idempotency-Key' => 'bulk-key-read'],
        )->assertStatus(202);

        $operation = CommunicationConversationBulkOperation::query()
            ->where('public_id', $response->json('data.id'))
            ->firstOrFail();
        app(ConversationBulkOperationProcessor::class)->process((int) $operation->id);

        $operation->refresh();
        $this->assertSame(ConversationBulkOperationStatus::Completed, $operation->status);
        $this->assertSame(0, CommunicationConversationUnreadMessage::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->count());
        $this->assertNull($message->fresh()->read_at);
        $this->assertSame(
            ConversationBulkItemStatus::Succeeded,
            $operation->items()->first()->status,
        );
    }

    public function test_list_rejects_client_tenant_id(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $this->member($inbox, $operator);
        $this->authenticate($operator);

        $this->getJson('/api/v1/communication/conversations?tenant_id=1')
            ->assertUnprocessable();
        $this->postJson(
            '/api/v1/communication/conversation-bulk-operations',
            [
                'tenant_id' => $tenant->id,
                'action' => 'MARK_READ',
                'items' => [],
            ],
            ['Idempotency-Key' => 'bulk-key-tenant'],
        )->assertUnprocessable();
    }

    public function test_retention_purges_terminal_operations_without_touching_events(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, 'Atendimento');
        $conversation = $this->conversation($tenant, $inbox, '+5511999900060');

        $old = CommunicationConversationBulkOperation::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $operator->id,
            'access_mode' => 'membership',
            'idempotency_key' => 'old-op',
            'payload_digest' => hash('sha256', 'old'),
            'action' => 'SET_STATUS',
            'params' => ['status' => 'RESOLVED'],
            'status' => ConversationBulkOperationStatus::Completed,
            'item_count' => 1,
            'succeeded_count' => 1,
            'completed_at' => now()->subDays(45),
            'queued_at' => now()->subDays(45),
        ]);
        CommunicationConversationBulkOperationItem::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenant->id,
            'bulk_operation_id' => $old->id,
            'item_index' => 0,
            'conversation_id' => $conversation->id,
            'inbox_id' => $inbox->id,
            'status' => ConversationBulkItemStatus::Succeeded,
            'processed_at' => now()->subDays(45),
        ]);

        $active = CommunicationConversationBulkOperation::query()->withoutGlobalScopes()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $operator->id,
            'access_mode' => 'membership',
            'idempotency_key' => 'active-op',
            'payload_digest' => hash('sha256', 'active'),
            'action' => 'SET_STATUS',
            'params' => ['status' => 'OPEN'],
            'status' => ConversationBulkOperationStatus::Running,
            'item_count' => 1,
            'queued_at' => now()->subDays(45),
        ]);

        $this->artisan('communication:purge-expired-bulk-operations')->assertSuccessful();

        $this->assertDatabaseMissing('communication_conversation_bulk_operations', ['id' => $old->id]);
        $this->assertDatabaseHas('communication_conversation_bulk_operations', ['id' => $active->id]);
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function seedUnread(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): void {
        CommunicationConversationUnreadMessage::query()->withoutGlobalScopes()->insertOrIgnore([[
            'tenant_id' => $conversation->tenant_id,
            'inbox_id' => $conversation->inbox_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }

    private function label(Tenant $tenant, string $name): CommunicationLabel
    {
        return CommunicationLabel::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'color' => '#336699',
        ]);
    }

    private function inbox(Tenant $tenant, string $name): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
    }

    private function member(CommunicationInbox $inbox, User $user): void
    {
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id,
            'inbox_id' => $inbox->id,
            'tenant_membership_id' => $membership->id,
            'is_active' => true,
        ]);
    }

    private function conversation(
        Tenant $tenant,
        CommunicationInbox $inbox,
        string $address,
    ): CommunicationConversation {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Contato '.substr($address, -4),
            'is_provisional' => false,
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);

        return CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function message(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        string $body,
        MessageDirection $direction = MessageDirection::Inbound,
    ): CommunicationMessage {
        return CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => $direction,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'body_encrypted' => $body,
            'provider_message_id' => 'provider-'.strtolower((string) Str::ulid()),
            'content_digest' => hash('sha256', $body),
            'occurred_at' => now(),
        ]);
    }
}
