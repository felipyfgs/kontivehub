<?php

namespace Tests\Feature\Communication;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationTransportException;
use App\Jobs\Communication\ReconcileInboxIdentityProfileJob;
use App\Jobs\Communication\ReconcileInboxIdentityProfilesJob;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\Tenant;
use App\Services\Communication\Contact\InboxIdentityProfileMerger;
use App\Services\Communication\Contact\InboxIdentityProfileReconciler;
use App\Services\Communication\Contact\InboxIdentityProfileReconciliationScheduler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationContactProfileReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
        ]);
    }

    public function test_directed_reconciliation_resolves_aliases_with_deterministic_precedence(): void
    {
        [$tenant, $inbox] = $this->context();
        $contact = $this->contact($tenant);
        $canonical = $this->identity($tenant, $contact, '+5511999990001');
        $alias = $this->identity($tenant, $contact, 'lid:149865032093945', $canonical->id);
        $this->conversation($tenant, $inbox, $canonical);
        $transport = new ContactProfileReconciliationTransportFake(['profiles' => [
            [
                'user' => (string) $canonical->address_encrypted,
                'found' => true,
                'push_name' => 'Push canônico',
            ],
            [
                'user' => (string) $alias->address_encrypted,
                'found' => true,
                'address_book_full_name' => 'Nome da agenda no alias',
                'push_name' => 'Push do alias',
            ],
        ]]);
        $this->app->instance(CommunicationTransport::class, $transport);
        $reconciler = app(InboxIdentityProfileReconciler::class);
        $observedAt = '2026-08-02T12:00:00.000000Z';
        $reconciliationId = 'reconcile-directed-alias';

        self::assertSame(1, $reconciler->reconcileIdentity(
            $inbox,
            $alias,
            $observedAt,
            $reconciliationId,
        ));
        self::assertSame(1, $reconciler->reconcileIdentity(
            $inbox,
            $alias,
            $observedAt,
            $reconciliationId,
        ));

        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->sole();
        self::assertSame($canonical->id, $profile->identity_id);
        self::assertSame('Nome da agenda no alias', $profile->address_book_full_name);
        self::assertSame('Push canônico', $profile->push_name);
        self::assertSame(
            $reconciliationId.':'.$canonical->id,
            data_get($profile->field_versions, 'push_name.event_id'),
        );
        self::assertNull($contact->refresh()->name);
        self::assertSame(
            [(string) $canonical->address_encrypted, (string) $alias->address_encrypted],
            $transport->queries[0]->payload['users'],
        );
        self::assertSame($transport->queries[0]->queryId, $transport->queries[1]->queryId);
        self::assertSame(0, $transport->commands);
        $projectionEvent = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('type', 'contact.profile.updated')
            ->sole();
        self::assertSame([
            'inbox_id' => $inbox->id,
            'identity_id' => $canonical->id,
            'changed_fields' => ['address_book_full_name', 'push_name'],
        ], $projectionEvent->payload);
    }

    public function test_unknown_failures_and_not_found_never_clear_or_expand_known_contacts(): void
    {
        [$tenant, $inbox] = $this->context();
        $knownContact = $this->contact($tenant);
        $knownIdentity = $this->identity($tenant, $knownContact, '+5511999990002');
        $this->conversation($tenant, $inbox, $knownIdentity);
        app(InboxIdentityProfileMerger::class)->merge(
            $inbox,
            $knownIdentity,
            ['push_name' => 'Nome preservado'],
            now()->subMinute(),
            'seed-profile',
        );
        $notFound = new ContactProfileReconciliationTransportFake(['profiles' => [[
            'user' => (string) $knownIdentity->address_encrypted,
            'found' => false,
            'cleared_fields' => ['push_name'],
        ]]]);
        $this->app->instance(CommunicationTransport::class, $notFound);

        self::assertSame(0, app(InboxIdentityProfileReconciler::class)->reconcileIdentity(
            $inbox,
            $knownIdentity,
        ));
        self::assertSame(
            'Nome preservado',
            CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->sole()->push_name,
        );

        $unknownContact = $this->contact($tenant);
        $unknownIdentity = $this->identity($tenant, $unknownContact, '+5511999990003');
        self::assertSame(0, app(InboxIdentityProfileReconciler::class)->reconcileIdentity(
            $inbox,
            $unknownIdentity,
        ));
        self::assertSame(1, $notFound->queryCount());
        self::assertSame(2, CommunicationContact::query()->withoutGlobalScopes()->count());

        $failure = new ContactProfileReconciliationTransportFake(
            new CommunicationTransportException('QUERY_UNAVAILABLE', true, 503),
        );
        $this->app->instance(CommunicationTransport::class, $failure);
        try {
            app(InboxIdentityProfileReconciler::class)->reconcileIdentity($inbox, $knownIdentity);
            self::fail('A falha retryable do store deveria ser propagada para a fila.');
        } catch (CommunicationTransportException $error) {
            self::assertSame('QUERY_UNAVAILABLE', $error->errorCode);
        }
        self::assertSame(
            'Nome preservado',
            CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->sole()->push_name,
        );
    }

    public function test_sweep_pages_known_identities_in_queries_of_at_most_one_hundred_users(): void
    {
        [$tenant, $inbox] = $this->context();
        $addresses = $this->seedKnownIdentities($tenant, $inbox, 101);
        $transport = new ContactProfileReconciliationTransportFake(['profiles' => []]);
        $this->app->instance(CommunicationTransport::class, $transport);
        $observedAt = '2026-08-02T13:00:00.000000Z';
        $reconciliationId = 'reconcile-paged-sweep';
        $first = new ReconcileInboxIdentityProfilesJob(
            $tenant->id,
            $inbox->id,
            observedAt: $observedAt,
            reconciliationId: $reconciliationId,
        );

        $first->handle(app(InboxIdentityProfileReconciler::class));

        Queue::assertPushed(ReconcileInboxIdentityProfilesJob::class, function ($job) use (
            $tenant,
            $inbox,
            $observedAt,
            $reconciliationId,
        ): bool {
            return $job->tenantId === $tenant->id
                && $job->inboxId === $inbox->id
                && $job->afterIdentityId > 0
                && $job->observedAt === $observedAt
                && $job->reconciliationId === $reconciliationId;
        });
        $next = Queue::pushed(ReconcileInboxIdentityProfilesJob::class)->first();
        self::assertInstanceOf(ReconcileInboxIdentityProfilesJob::class, $next);
        Queue::fake();

        $next->handle(app(InboxIdentityProfileReconciler::class));

        Queue::assertNothingPushed();
        self::assertSame(2, $transport->queryCount());
        self::assertSame([100, 1], array_map(
            static fn (GatewayQueryData $query): int => count($query->payload['users']),
            $transport->queries,
        ));
        self::assertEqualsCanonicalizing(
            $addresses,
            array_merge(...array_map(
                static fn (GatewayQueryData $query): array => $query->payload['users'],
                $transport->queries,
            )),
        );
        self::assertSame(0, $transport->commands);
    }

    public function test_jobs_share_the_inbox_lock_and_scheduler_dispatches_after_commit_on_communication_queue(): void
    {
        [$tenant, $inbox] = $this->context();
        $contact = $this->contact($tenant);
        $identity = $this->identity($tenant, $contact, '+5511999990004');
        $this->conversation($tenant, $inbox, $identity);
        $directed = new ReconcileInboxIdentityProfileJob($tenant->id, $inbox->id, $identity->id);
        $sweep = new ReconcileInboxIdentityProfilesJob($tenant->id, $inbox->id);

        self::assertInstanceOf(ShouldBeUnique::class, $directed);
        self::assertSame('communication', $directed->queue);
        self::assertSame('communication', $sweep->queue);
        self::assertTrue($directed->afterCommit);
        self::assertTrue($sweep->afterCommit);
        self::assertTrue($directed->middleware()[0]->shareKey);
        self::assertTrue($sweep->middleware()[0]->shareKey);
        self::assertSame($directed->middleware()[0]->key, $sweep->middleware()[0]->key);

        app(InboxIdentityProfileReconciliationScheduler::class)->schedule($inbox, $identity);

        Queue::assertPushedOn(
            'communication',
            ReconcileInboxIdentityProfileJob::class,
            fn ($job): bool => $job->tenantId === $tenant->id
                && $job->inboxId === $inbox->id
                && $job->identityId === $identity->id
                && $job->afterCommit === true,
        );
    }

    public function test_identity_profile_merge_emits_sanitized_name_projection_event_without_picture_change(): void
    {
        [$tenant, $inbox] = $this->context();
        $contact = $this->contact($tenant);
        $survivor = $this->identity($tenant, $contact, '+5511999990005');
        $donor = $this->identity($tenant, $contact, 'lid:149865032093946');
        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $survivor->id,
            'push_name' => 'Nome anterior',
            'field_versions' => [
                'push_name' => [
                    'observed_at' => '2026-08-02T10:00:00.000000Z',
                    'event_id' => 'target-profile',
                ],
            ],
            'cleared_fields' => [],
        ]);
        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $donor->id,
            'push_name' => 'Nome mais novo',
            'field_versions' => [
                'push_name' => [
                    'observed_at' => '2026-08-02T11:00:00.000000Z',
                    'event_id' => 'donor-profile',
                ],
            ],
            'cleared_fields' => [],
        ]);

        app(InboxIdentityProfileMerger::class)->mergeFromDonor($survivor, $donor);

        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->sole();
        self::assertSame($survivor->id, $profile->identity_id);
        self::assertSame('Nome mais novo', $profile->push_name);
        $projectionEvent = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('type', 'contact.profile.updated')
            ->sole();
        self::assertSame([
            'inbox_id' => $inbox->id,
            'identity_id' => $survivor->id,
            'changed_fields' => ['push_name'],
        ], $projectionEvent->payload);
    }

    /** @return array{Tenant, CommunicationInbox} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Reconciliação',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);

        return [$tenant, $inbox];
    }

    private function contact(Tenant $tenant): CommunicationContact
    {
        return CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'is_provisional' => true,
            'is_active' => true,
        ]);
    }

    private function identity(
        Tenant $tenant,
        CommunicationContact $contact,
        string $address,
        ?int $canonicalIdentityId = null,
    ): CommunicationIdentity {
        return CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'canonical_identity_id' => $canonicalIdentityId,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
    }

    private function conversation(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
    ): CommunicationConversation {
        return CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function seedKnownIdentities(
        Tenant $tenant,
        CommunicationInbox $inbox,
        int $count,
    ): array {
        $contact = $this->contact($tenant);
        $timestamp = now();
        $seed = strtolower((string) Str::ulid());
        $addresses = [];
        $identities = [];
        for ($index = 0; $index < $count; $index++) {
            $address = '+5511'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
            $addresses[] = $address;
            $identities[] = [
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'canonical_identity_id' => null,
                'channel' => CommunicationChannel::WhatsApp->value,
                'address_encrypted' => Crypt::encryptString($address),
                'address_hash' => hash('sha256', $seed.':'.$address),
                'address_masked' => '***'.substr($address, -4),
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        DB::table('communication_identities')->insert($identities);
        $conversations = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contact_id', $contact->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($identityId): array => [
                'tenant_id' => $tenant->id,
                'inbox_id' => $inbox->id,
                'identity_id' => $identityId,
                'status' => ConversationStatus::Open->value,
                'last_message_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all();
        DB::table('communication_conversations')->insert($conversations);

        return $addresses;
    }
}

final class ContactProfileReconciliationTransportFake implements CommunicationTransport
{
    /** @var list<GatewayQueryData> */
    public array $queries = [];

    public int $commands = 0;

    /** @param array<string, mixed>|CommunicationTransportException $result */
    public function __construct(private readonly array|CommunicationTransportException $result) {}

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        $this->commands++;

        throw new \LogicException('A reconciliação não pode enviar comandos ao gateway.');
    }

    public function query(GatewayQueryData $query): array
    {
        $this->queries[] = $query;
        if ($this->result instanceof CommunicationTransportException) {
            throw $this->result;
        }

        return $this->result;
    }

    public function sessionStatus(string $sessionId): array
    {
        throw new \LogicException('Não esperado.');
    }

    public function downloadMedia(string $spoolId): StreamInterface
    {
        throw new \LogicException('Não esperado.');
    }

    public function queryCount(): int
    {
        return count($this->queries);
    }
}
