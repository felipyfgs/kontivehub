<?php

namespace Tests\Feature\Communication;

use App\Actions\Communication\DeleteCommunicationInboxAction;
use App\Console\Commands\DispatchCommunicationProfilePictureRefreshesCommand;
use App\Contracts\CommunicationProfilePictureDownloader;
use App\Contracts\CommunicationTransport;
use App\DTO\Communication\DownloadedProfilePicture;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\ProfilePictureState;
use App\Enums\CommunicationChannel;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Exceptions\CommunicationProfilePictureDownloadException;
use App\Exceptions\CommunicationTransportException;
use App\Jobs\Communication\DeleteCommunicationMediaObjectJob;
use App\Jobs\Communication\ReconcileCommunicationInboxIdentityProfilesJob;
use App\Jobs\Communication\RefreshCommunicationProfilePictureJob;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationMediaDeletionIntent;
use App\Models\CommunicationMessage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Contact\CommunicationInboxIdentityProfileMerger;
use App\Services\Communication\Contact\CommunicationInboxIdentityProfileReconciler;
use App\Services\Communication\Media\CommunicationMediaDeletionService;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Services\Communication\ProfilePicture\CommunicationProfilePictureRefreshScheduler;
use App\Support\CurrentTenant;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationProfilePictureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.media.disk_root' => sys_get_temp_dir().'/communication-profile-picture-tests-'.Str::ulid(),
        ]);
    }

    public function test_ready_picture_is_private_revalidatable_and_never_served_after_access_is_lost(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $member = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $outsider = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $member);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = $this->readyProfile($tenant, $inbox, CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id));
        $url = '/api/v1/communication/profile-pictures/'.$profile->id.'/'.$profile->profile_picture_version;

        $this->authenticate($member);
        $response = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('ETag', '"'.$profile->profile_picture_sha256.'"')
            ->assertHeader('Cache-Control');
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        self::assertSame("\x89PNG\r\n\x1a\nprofile-picture", $response->streamedContent());
        $this->get($url, ['If-None-Match' => '"'.$profile->profile_picture_sha256.'"'])->assertStatus(304);
        $this->get('/api/v1/communication/profile-pictures/'.$profile->id.'/999')->assertNotFound();

        $this->authenticate($foreignAdmin);
        $this->get($url)->assertNotFound();

        $this->authenticate($outsider);
        $this->get($url)->assertNotFound();

        CommunicationInboxMember::query()->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('tenant_membership_id', TenantMembership::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $member->id)->value('id'))
            ->delete();
        $this->authenticate($member);
        $this->get($url)->assertNotFound();
    }

    public function test_picture_stream_is_throttled_before_a_missing_object_can_be_opened(): void
    {
        config([
            'communication.profile_pictures.stream_rate_limit_per_minute' => 1,
            'communication.profile_pictures.stream_ip_rate_limit_per_minute' => 1,
        ]);
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $member = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $member);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = $this->readyProfile(
            $tenant,
            $inbox,
            CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id),
        );
        $url = '/api/v1/communication/profile-pictures/'.$profile->id.'/'.$profile->profile_picture_version;

        $this->authenticate($member);
        $this->get($url)->assertOk();
        app(CommunicationMediaStore::class)->delete((string) $profile->profile_picture_object_id);

        // If the controller/action ran before the limiter, the missing object
        // would produce 404. The second request must be rejected at middleware.
        $this->get($url)
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Remaining', '0');
    }

    public function test_missing_or_not_ready_picture_returns_not_found_without_leaking_asset_metadata(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $member = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $member);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Unavailable,
            'profile_picture_version' => 2,
        ]);

        $this->authenticate($member);
        $this->get('/api/v1/communication/profile-pictures/'.$profile->id.'/2')
            ->assertNotFound()
            ->assertDontSee('profile_picture_object_id');
    }

    public function test_ready_projection_with_missing_encrypted_object_returns_not_found(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $member = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $member);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = $this->readyProfile(
            $tenant,
            $inbox,
            CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id),
        );
        app(CommunicationMediaStore::class)->delete((string) $profile->profile_picture_object_id);

        $this->authenticate($member);
        $this->get('/api/v1/communication/profile-pictures/'.$profile->id.'/'.$profile->profile_picture_version)
            ->assertNotFound();
    }

    public function test_ready_database_constraint_rejects_an_incomplete_projection(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);

        $this->expectException(QueryException::class);
        DB::table('communication_inbox_identity_profiles')->insert([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Ready->value,
            'profile_picture_version' => 1,
            'field_versions' => '[]',
            'cleared_fields' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_ready_database_constraint_rejects_a_storage_context_from_another_version(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);

        $this->expectException(QueryException::class);
        DB::table('communication_inbox_identity_profiles')->where('id', $profile->id)->update([
            'profile_picture_state' => ProfilePictureState::Ready->value,
            'profile_picture_object_id' => (string) Str::ulid(),
            'profile_picture_mime_type' => 'image/png',
            'profile_picture_size_bytes' => 12,
            'profile_picture_sha256' => str_repeat('a', 64),
            'profile_picture_storage_context' => json_encode([
                'tenant_id' => (int) $tenant->id,
                'inbox_id' => (int) $inbox->id,
                'profile_id' => (int) $profile->id,
                'version' => 2,
                'purpose' => 'COMMUNICATION_MEDIA',
            ], JSON_THROW_ON_ERROR),
            'profile_picture_fetched_at' => now(),
        ]);
    }

    public function test_refresh_is_fail_closed_when_tenant_communication_is_unavailable(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending, 'profile_picture_version' => 1,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id);
        $transport = new ProfilePictureTransport(['profile_picture' => [
            'user' => $identity->address_encrypted,
            'id' => 'provider-picture-v1',
            'url' => 'https://cdn.example.test/picture.png',
        ]]);
        $tenant->forceFill(['communication_enabled' => false])->save();

        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle($transport, new ProfilePictureDownloaderFake, app(CommunicationMediaStore::class), app(CommunicationMediaDeletionService::class));

        self::assertSame(0, $transport->queries);
        self::assertSame(ProfilePictureState::Pending, $profile->refresh()->profile_picture_state);
    }

    public function test_profile_reconciliation_queries_only_an_operational_inbox(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $this->conversation($tenant, $inbox);
        $transport = new ProfilePictureTransport(['profiles' => []]);
        $this->app->instance(CommunicationTransport::class, $transport);

        $result = app(CommunicationInboxIdentityProfileReconciler::class)->reconcile($inbox);

        self::assertSame(['applied' => 0, 'next_identity_id' => null], $result);
        self::assertSame(1, $transport->queries);
        self::assertSame(GatewayQueryType::ContactProfiles, $transport->lastQuery?->type);
        self::assertSame($inbox->session_id, $transport->lastQuery?->sessionId);
    }

    public function test_profile_reconciliation_is_fail_closed_for_unavailable_runtime_states(): void
    {
        $scenarios = [
            'communication disabled' => static function (Tenant $tenant, CommunicationInbox $inbox): void {
                config(['communication.enabled' => false]);
            },
            'gateway disabled' => static function (Tenant $tenant, CommunicationInbox $inbox): void {
                config(['communication.gateway.enabled' => false]);
            },
            'tenant communication disabled' => static function (Tenant $tenant, CommunicationInbox $inbox): void {
                $tenant->forceFill(['communication_enabled' => false])->save();
            },
            'tenant inactive' => static function (Tenant $tenant, CommunicationInbox $inbox): void {
                $tenant->forceFill(['is_active' => false])->save();
            },
            'inbox disabled' => static function (Tenant $tenant, CommunicationInbox $inbox): void {
                $inbox->forceFill(['is_enabled' => false])->save();
            },
            'inbox disconnected' => static function (Tenant $tenant, CommunicationInbox $inbox): void {
                $inbox->forceFill(['status' => InboxStatus::Disconnected])->save();
            },
        ];

        foreach ($scenarios as $label => $disableRuntime) {
            config([
                'communication.enabled' => true,
                'communication.gateway.enabled' => true,
            ]);
            $tenant = Tenant::factory()->create(['communication_enabled' => true]);
            $inbox = $this->inbox($tenant);
            $this->conversation($tenant, $inbox);
            $disableRuntime($tenant, $inbox);
            $inbox->unsetRelation('tenant');
            $transport = new ProfilePictureTransport(['profiles' => []]);
            $this->app->instance(CommunicationTransport::class, $transport);

            $result = app(CommunicationInboxIdentityProfileReconciler::class)->reconcile($inbox);

            self::assertSame(['applied' => 0, 'next_identity_id' => null], $result, $label);
            self::assertSame(0, $transport->queries, $label);
        }
    }

    public function test_reconciliation_job_rechecks_inbox_availability_when_it_starts(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $this->conversation($tenant, $inbox);
        $job = new ReconcileCommunicationInboxIdentityProfilesJob($tenant->id, $inbox->id);
        $inbox->forceFill(['status' => InboxStatus::Disconnected])->save();
        $transport = new ProfilePictureTransport(['profiles' => []]);
        $this->app->instance(CommunicationTransport::class, $transport);
        Queue::fake();

        $job->handle(app(CommunicationInboxIdentityProfileReconciler::class));

        self::assertSame(0, $transport->queries);
        Queue::assertNothingPushed();
    }

    public function test_refresh_uses_preview_and_promotes_downloaded_picture_without_provider_url(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending, 'profile_picture_version' => 1,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id);
        $transport = new ProfilePictureTransport(['profile_picture' => [
            'user' => $identity->address_encrypted,
            'id' => 'provider-picture-v1',
            'url' => 'https://cdn.example.test/picture.png',
        ]]);
        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle($transport, new ProfilePictureDownloaderFake, app(CommunicationMediaStore::class), app(CommunicationMediaDeletionService::class));

        $profile->refresh();
        self::assertSame(ProfilePictureState::Ready, $profile->profile_picture_state);
        self::assertSame('provider-picture-v1', $profile->picture_id);
        self::assertNotNull($profile->profile_picture_object_id);
        self::assertSame('image/png', $profile->profile_picture_mime_type);
        self::assertSame(['user' => $identity->address_encrypted, 'preview' => true], $transport->lastQuery?->payload);
        self::assertNull($profile->getAttribute('url'));
    }

    public function test_refresh_promotes_a_valid_picture_without_converting_a_missing_provider_id_to_an_empty_string(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending, 'profile_picture_version' => 1,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id);
        $transport = new ProfilePictureTransport(['profile_picture' => [
            'user' => $identity->address_encrypted,
            'url' => 'https://cdn.example.test/picture.png',
        ]]);

        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            $transport,
            new ProfilePictureDownloaderFake,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        $profile->refresh();
        self::assertSame(ProfilePictureState::Ready, $profile->profile_picture_state);
        self::assertNull($profile->picture_id);
        self::assertNotNull($profile->profile_picture_object_id);
    }

    public function test_refresh_marks_nil_gateway_picture_unavailable_for_negative_cache(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending, 'profile_picture_version' => 1,
        ]);
        config(['communication.profile_pictures.negative_ttl_seconds' => 60]);

        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(new ProfilePictureTransport(['profile_picture' => null]), new ProfilePictureDownloaderFake, app(CommunicationMediaStore::class), app(CommunicationMediaDeletionService::class));

        $profile->refresh();
        self::assertSame(ProfilePictureState::Unavailable, $profile->profile_picture_state);
        self::assertNotNull($profile->profile_picture_retry_at);
    }

    public function test_refresh_maps_gateway_privacy_to_negative_cache_without_retrying(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);
        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            new ProfilePictureTransport(new CommunicationTransportException('PROFILE_PICTURE_PRIVACY', false, 403)),
            new ProfilePictureDownloaderFake,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        $profile->refresh();
        self::assertSame(ProfilePictureState::Unavailable, $profile->profile_picture_state);
        self::assertSame('UNAVAILABLE', $profile->profile_picture_error_code);
        self::assertNotNull($profile->profile_picture_retry_at);
    }

    public function test_refresh_and_dispatch_stop_after_tenant_opt_out_or_inbox_transport_loss(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id);
        $transport = new ProfilePictureTransport(['profile_picture' => [
            'user' => $identity->address_encrypted,
            'id' => 'provider-picture-v1',
            'url' => 'https://cdn.example.test/picture.png',
        ]]);
        $tenant->forceFill(['communication_enabled' => false])->save();
        app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle();
        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            $transport,
            new ProfilePictureDownloaderFake,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        $tenant->forceFill(['communication_enabled' => true])->save();
        $inbox->forceFill(['status' => InboxStatus::Disconnected])->save();
        app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle();
        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            $transport,
            new ProfilePictureDownloaderFake,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        $inbox->forceFill(['status' => InboxStatus::Connected, 'is_enabled' => false])->save();
        app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle();
        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            $transport,
            new ProfilePictureDownloaderFake,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        $inbox->forceFill(['is_enabled' => true])->save();
        $tenant->forceFill(['is_active' => false])->save();
        app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle();
        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            $transport,
            new ProfilePictureDownloaderFake,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        $tenant->forceFill([
            'is_active' => true,
            'lifecycle_status' => TenantLifecycleStatus::Suspended,
        ])->save();
        app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle();
        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            $transport,
            new ProfilePictureDownloaderFake,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        self::assertSame(0, $transport->queries);
        self::assertSame(ProfilePictureState::Pending, $profile->refresh()->profile_picture_state);
        Queue::assertNothingPushed();
    }

    public function test_native_scheduler_is_due_only_and_keeps_uniqueness_until_processing_finishes(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Refresh nativo',
            'is_active' => true,
        ]);
        $identity = $this->identityForContact($tenant, $contact, '+5511999994321');
        $scheduler = app(CommunicationProfilePictureRefreshScheduler::class);

        $profile = $scheduler->schedule($inbox, $identity);

        self::assertInstanceOf(CommunicationInboxIdentityProfile::class, $profile);
        Queue::assertPushed(RefreshCommunicationProfilePictureJob::class);
        $job = Queue::pushed(RefreshCommunicationProfilePictureJob::class)->first();
        self::assertInstanceOf(ShouldBeUnique::class, $job);
        self::assertNotSame(
            $job->middleware()[0]->key,
            (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 2))->middleware()[0]->key,
        );

        $this->promoteProfile($profile);
        Queue::fake();
        $scheduler->schedule($inbox, $identity);
        Queue::assertNothingPushed();
    }

    public function test_contact_list_and_detail_choose_the_most_recent_ready_canonical_picture_visible_to_actor(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $visible = $this->inbox($tenant);
        $hidden = $this->inbox($tenant);
        $this->member($visible, $actor);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Alias canônico', 'is_active' => true,
        ]);
        $pn = $this->identityForContact($tenant, $contact, '+5511999991234');
        $lid = $this->identityForContact($tenant, $contact, 'lid:149865032093945', $pn->id);
        $visibleConversation = $this->conversationForIdentity($tenant, $visible, $lid, now()->subHour());
        $this->conversationForIdentity($tenant, $hidden, $lid, now());
        $visiblePicture = $this->projectedProfile($tenant, $visible, $pn, 1);
        $hiddenPicture = $this->projectedProfile($tenant, $hidden, $pn, 2);

        $this->authenticate($actor);
        $expected = '/api/v1/communication/profile-pictures/'.$visiblePicture->id.'/1';
        $this->getJson('/api/v1/communication/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $contact->id)
            ->assertJsonPath('data.0.profile_picture_url', $expected);
        $this->getJson('/api/v1/communication/contacts/'.$contact->id)
            ->assertOk()
            ->assertJsonPath('data.profile_picture_url', $expected);

        // A conversa usa a mesma identidade LID, mas deve resolver o perfil PN canônico na inbox dela.
        $this->getJson('/api/v1/communication/conversations/'.$visibleConversation->id)
            ->assertOk()
            ->assertJsonPath('data.contact.profile_picture_url', $expected);
        self::assertNotSame($hiddenPicture->id, $visiblePicture->id);
    }

    public function test_contact_and_conversation_mutations_preserve_ready_picture_projection(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Mutação com foto',
            'is_active' => true,
        ]);
        $identity = $this->identityForContact($tenant, $contact, '+5511999997100');
        $conversation = $this->conversationForIdentity($tenant, $inbox, $identity, now());
        $profile = $this->readyProfile($tenant, $inbox, $identity);
        $expected = '/api/v1/communication/profile-pictures/'.$profile->id.'/'.$profile->profile_picture_version;
        $this->authenticate($admin);

        $this->patchJson('/api/v1/communication/contacts/'.$contact->id, [
            'name' => 'Mutação com foto atualizada',
        ])->assertOk()
            ->assertJsonPath('data.profile_picture_url', $expected)
            ->assertJsonPath('data.profile_picture_state', ProfilePictureState::Ready->value);
        $this->patchJson('/api/v1/communication/conversations/'.$conversation->id, [
            'lock_version' => (int) $conversation->refresh()->lock_version,
            'priority' => 2,
        ])->assertOk()
            ->assertJsonPath('data.contact.profile_picture_url', $expected)
            ->assertJsonPath('data.contact.profile_picture_state', ProfilePictureState::Ready->value);
    }

    public function test_contact_projection_uses_one_profile_and_includes_profiles_without_conversation(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant);
        $this->member($inbox, $actor);

        $readyContact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'A profile sem conversa ready',
            'is_active' => true,
        ]);
        $readyIdentity = $this->identityForContact($tenant, $readyContact, '+5511999997001');
        $readyProfile = $this->readyProfile($tenant, $inbox, $readyIdentity);

        $unavailableContact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'B profile sem conversa unavailable',
            'is_active' => true,
        ]);
        $unavailableIdentity = $this->identityForContact($tenant, $unavailableContact, '+5511999997002');
        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $unavailableIdentity->id,
            'profile_picture_state' => ProfilePictureState::Unavailable,
            'profile_picture_version' => 1,
        ]);

        $this->authenticate($actor);
        $contacts = collect($this->getJson('/api/v1/communication/contacts')->assertOk()->json('data'));
        $ready = $contacts->firstWhere('id', $readyContact->id);
        $unavailable = $contacts->firstWhere('id', $unavailableContact->id);

        self::assertSame(ProfilePictureState::Ready->value, $ready['profile_picture_state'] ?? null);
        self::assertSame(
            '/api/v1/communication/profile-pictures/'.$readyProfile->id.'/'.$readyProfile->profile_picture_version,
            $ready['profile_picture_url'] ?? null,
        );
        self::assertSame(ProfilePictureState::Unavailable->value, $unavailable['profile_picture_state'] ?? null);
        self::assertNull($unavailable['profile_picture_url'] ?? null);
        $this->getJson('/api/v1/communication/contacts/'.$readyContact->id)
            ->assertOk()
            ->assertJsonPath('data.profile_picture_state', ProfilePictureState::Ready->value)
            ->assertJsonPath(
                'data.profile_picture_url',
                '/api/v1/communication/profile-pictures/'.$readyProfile->id.'/'.$readyProfile->profile_picture_version,
            );
    }

    public function test_refresh_dispatch_command_is_noop_by_default_and_applies_fair_global_and_inbox_limits(): void
    {
        Queue::fake();
        config(['communication.enabled' => false]);
        self::assertSame(0, app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle());
        Queue::assertNothingPushed();

        $firstTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $secondTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $busyInbox = $this->inbox($firstTenant);
        $quietInbox = $this->inbox($firstTenant);
        $thirdInbox = $this->inbox($secondTenant);
        $fourthInbox = $this->inbox($secondTenant);
        $fifthInbox = $this->inbox($secondTenant);
        config([
            'communication.enabled' => true,
            'communication.profile_pictures.batch_size' => 100,
            'communication.profile_pictures.inbox_batch_size' => 25,
            'communication.profile_pictures.refresh_ttl_seconds' => 60,
        ]);

        // The busy inbox alone exceeds the former fixed 1,000-row window.
        // Other inboxes and another tenant must still enter the ranked result.
        $this->seedBackfillCandidates($firstTenant, $busyInbox, 1_001, now());
        $this->seedBackfillCandidates($firstTenant, $quietInbox, 26, now()->subMinute());
        $this->seedBackfillCandidates($secondTenant, $thirdInbox, 26, now()->subMinutes(2));
        $this->seedBackfillCandidates($secondTenant, $fourthInbox, 26, now()->subMinutes(3));
        $this->seedBackfillCandidates($secondTenant, $fifthInbox, 26, now()->subMinutes(4));

        self::assertSame(0, app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle());
        Queue::assertPushed(RefreshCommunicationProfilePictureJob::class, 100);
        Queue::assertPushed(RefreshCommunicationProfilePictureJob::class, function (RefreshCommunicationProfilePictureJob $job): bool {
            return $job->version === 1 && $job->uniqueId() === $job->tenantId.':'.$job->profileId.':1';
        });

        $jobs = Queue::pushed(RefreshCommunicationProfilePictureJob::class);
        $profiles = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
            ->whereIn('id', $jobs->pluck('profileId')->all())
            ->get();
        $counts = $profiles->countBy(fn (CommunicationInboxIdentityProfile $profile): string => $profile->tenant_id.':'.$profile->inbox_id);
        self::assertSame(25, $counts[$firstTenant->id.':'.$busyInbox->id] ?? 0);
        self::assertSame(25, $counts[$firstTenant->id.':'.$quietInbox->id] ?? 0);
        self::assertSame(25, $counts[$secondTenant->id.':'.$thirdInbox->id] ?? 0);
        self::assertSame(25, $counts[$secondTenant->id.':'.$fourthInbox->id] ?? 0);
        self::assertSame(0, $counts[$secondTenant->id.':'.$fifthInbox->id] ?? 0);
        self::assertLessThanOrEqual(25, $counts->max());
    }

    public function test_dispatcher_includes_profile_without_conversation_and_deduplicates_canonical_alias(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Backfill sem conversa',
            'is_active' => true,
        ]);
        $withoutConversation = $this->identityForContact($tenant, $contact, '+5511999997101');
        $withoutConversationProfile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $withoutConversation->id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);
        $canonical = $this->identityForContact($tenant, $contact, '+5511999997102');
        $alias = $this->identityForContact($tenant, $contact, 'lid:7102', $canonical->id);
        $this->conversationForIdentity($tenant, $inbox, $alias, now());
        $canonicalProfile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $canonical->id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);

        app(DispatchCommunicationProfilePictureRefreshesCommand::class)->handle();

        Queue::assertPushed(RefreshCommunicationProfilePictureJob::class, 2);
        $profileIds = Queue::pushed(RefreshCommunicationProfilePictureJob::class)
            ->pluck('profileId')
            ->sort()
            ->values()
            ->all();
        self::assertSame(
            collect([$withoutConversationProfile->id, $canonicalProfile->id])->sort()->values()->all(),
            $profileIds,
        );
    }

    public function test_malformed_provider_identity_fails_closed_without_persisting_remote_data(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $conversation->identity_id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);
        try {
            (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
                new ProfilePictureTransport(['profile_picture' => [
                    'user' => '+5511888888888',
                    'id' => 'provider-picture-v1',
                    'url' => 'https://cdn.example.test/secret.png',
                ]]),
                new ProfilePictureDownloaderFake,
                app(CommunicationMediaStore::class),
                app(CommunicationMediaDeletionService::class),
            );
            self::fail('Resultado inesperado do provider deve ser relançado para a fila.');
        } catch (\RuntimeException $error) {
            self::assertSame('PROFILE_PICTURE_RESULT_REJECTED', $error->getMessage());
        }

        $profile->refresh();
        self::assertSame(ProfilePictureState::Failed, $profile->profile_picture_state);
        self::assertSame('FETCH_FAILED', $profile->profile_picture_error_code);
        self::assertNull($profile->profile_picture_object_id);
        self::assertNull($profile->picture_id);
        self::assertSame(0, CommunicationMediaDeletionIntent::query()->count());
    }

    public function test_transient_refresh_preserves_ready_asset_and_rethrows_for_queue_retry(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id);
        $profile = $this->readyProfile($tenant, $inbox, $identity);
        $profile->forceFill(['picture_id' => 'provider-picture-v1'])->save();
        $objectId = $profile->profile_picture_object_id;

        $failure = new CommunicationProfilePictureDownloadException('PROFILE_PICTURE_DOWNLOAD_TRANSIENT', true, 503);
        try {
            (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
                new ProfilePictureTransport(['profile_picture' => [
                    'user' => $identity->address_encrypted,
                    'id' => 'provider-picture-v1',
                    'url' => 'https://cdn.example.test/picture.png',
                ]]),
                new ProfilePictureDownloaderFake(error: $failure),
                app(CommunicationMediaStore::class),
                app(CommunicationMediaDeletionService::class),
            );
            self::fail('Falha transitória deveria ser devolvida à fila.');
        } catch (CommunicationProfilePictureDownloadException $error) {
            self::assertSame($failure, $error);
        }

        $profile->refresh();
        self::assertSame(ProfilePictureState::Ready, $profile->profile_picture_state);
        self::assertSame($objectId, $profile->profile_picture_object_id);
        self::assertSame('FETCH_FAILED', $profile->profile_picture_error_code);
        self::assertNotNull($profile->profile_picture_retry_at);
    }

    public function test_download_result_is_discarded_when_a_newer_generation_arrives_in_flight(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->findOrFail($conversation->identity_id);
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'picture_id' => 'provider-picture-v1',
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);
        $downloader = new ProfilePictureDownloaderFake(beforeReturn: function () use ($profile): void {
            $profile->refresh()->forceFill([
                'picture_id' => 'provider-picture-v2',
                'profile_picture_version' => 2,
                'profile_picture_state' => ProfilePictureState::Pending,
            ])->save();
        });

        (new RefreshCommunicationProfilePictureJob($tenant->id, $profile->id, 1))->handle(
            new ProfilePictureTransport(['profile_picture' => [
                'user' => $identity->address_encrypted,
                'id' => 'provider-picture-v1',
                'url' => 'https://cdn.example.test/picture.png',
            ]]),
            $downloader,
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );

        $profile->refresh();
        self::assertSame(2, $profile->profile_picture_version);
        self::assertSame('provider-picture-v2', $profile->picture_id);
        self::assertSame(ProfilePictureState::Pending, $profile->profile_picture_state);
        self::assertNull($profile->profile_picture_object_id);
        self::assertSame(1, $downloader->downloads);
        self::assertSame(1, CommunicationMediaDeletionIntent::query()->count());
    }

    public function test_ordered_picture_clear_hides_asset_and_records_durable_deletion(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Ordenação de foto',
            'is_active' => true,
        ]);
        $identity = $this->identityForContact($tenant, $contact, '+5511999994444');
        $merger = app(CommunicationInboxIdentityProfileMerger::class);
        $newerAt = now()->subMinute();
        $profile = $merger->merge($inbox, $identity, ['picture_id' => 'provider-v2'], $newerAt, 'event-v2');

        $merger->merge($inbox, $identity, ['picture_id' => 'provider-v1'], $newerAt->copy()->subMinute(), 'event-v1');
        $profile->refresh();
        self::assertSame('provider-v2', $profile->picture_id);
        self::assertSame(1, $profile->profile_picture_version);

        $this->promoteProfile($profile);
        $objectId = $profile->refresh()->profile_picture_object_id;
        $merger->merge($inbox, $identity, [], $newerAt->copy()->addMinute(), 'event-clear', ['picture_id']);

        $profile->refresh();
        self::assertNull($profile->picture_id);
        self::assertSame(ProfilePictureState::Unavailable, $profile->profile_picture_state);
        self::assertSame(2, $profile->profile_picture_version);
        self::assertNull($profile->profile_picture_object_id);
        $this->assertDatabaseHas('communication_media_deletion_intents', ['object_id' => $objectId]);
    }

    public function test_donor_ready_asset_follows_winning_picture_and_abandoned_object_is_queued(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Merge de foto',
            'is_active' => true,
        ]);
        $survivor = $this->identityForContact($tenant, $contact, '+5511999995555');
        $donor = $this->identityForContact($tenant, $contact, 'lid:9988776655');
        $target = $this->readyProfile($tenant, $inbox, $survivor);
        $target->forceFill([
            'picture_id' => 'provider-old',
            'field_versions' => ['picture_id' => ['observed_at' => '2026-07-29T10:00:00.000000Z', 'event_id' => 'event-old']],
        ])->save();
        $source = $this->readyProfile($tenant, $inbox, $donor);
        $source->forceFill([
            'picture_id' => 'provider-new',
            'field_versions' => ['picture_id' => ['observed_at' => '2026-07-29T11:00:00.000000Z', 'event_id' => 'event-new']],
        ])->save();
        $targetObject = $target->profile_picture_object_id;
        $sourceObject = $source->profile_picture_object_id;

        app(CommunicationInboxIdentityProfileMerger::class)->mergeFromDonor($survivor, $donor);

        $source->refresh();
        self::assertSame($survivor->id, $source->identity_id);
        self::assertSame('provider-new', $source->picture_id);
        self::assertSame(ProfilePictureState::Ready, $source->profile_picture_state);
        self::assertSame($sourceObject, $source->profile_picture_object_id);
        self::assertFalse(CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->whereKey($target->id)->exists());
        $this->assertDatabaseHas('communication_media_deletion_intents', ['object_id' => $targetObject]);
        $this->assertDatabaseHas('communication_events', [
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'type' => 'contact.profile_picture.updated',
        ]);
    }

    public function test_donor_picture_without_ordering_evidence_never_invalidates_ready_target(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Merge sem versão de foto',
            'is_active' => true,
        ]);
        $survivor = $this->identityForContact($tenant, $contact, '+5511999995656');
        $donor = $this->identityForContact($tenant, $contact, 'lid:9988776565');
        $target = $this->readyProfile($tenant, $inbox, $survivor);
        $target->forceFill([
            'picture_id' => 'provider-target',
            'field_versions' => [
                'picture_id' => [
                    'observed_at' => '2026-07-29T10:00:00.000000Z',
                    'event_id' => 'event-target',
                ],
            ],
        ])->save();
        $source = $this->readyProfile($tenant, $inbox, $donor);
        $source->forceFill([
            'picture_id' => 'provider-unordered',
            'field_versions' => [],
        ])->save();
        $targetObject = (string) $target->profile_picture_object_id;
        $sourceObject = (string) $source->profile_picture_object_id;

        app(CommunicationInboxIdentityProfileMerger::class)->mergeFromDonor($survivor, $donor);

        $target->refresh();
        self::assertSame('provider-target', $target->picture_id);
        self::assertSame(ProfilePictureState::Ready, $target->profile_picture_state);
        self::assertSame($targetObject, $target->profile_picture_object_id);
        self::assertFalse(
            CommunicationInboxIdentityProfile::query()
                ->withoutGlobalScopes()
                ->whereKey($source->id)
                ->exists(),
        );
        $this->assertDatabaseHas('communication_media_deletion_intents', ['object_id' => $sourceObject]);
        $this->assertDatabaseMissing('communication_media_deletion_intents', ['object_id' => $targetObject]);
    }

    public function test_export_and_purge_keep_only_safe_metadata_and_delete_asset_through_durable_intent(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Exportável',
            'is_active' => true,
        ]);
        $identity = $this->identityForContact($tenant, $contact, '+5511999996666');
        $this->conversationForIdentity($tenant, $inbox, $identity, now());
        $profile = $this->readyProfile($tenant, $inbox, $identity);
        $profile->forceFill(['picture_id' => 'provider-private-id'])->save();
        $objectId = (string) $profile->profile_picture_object_id;
        $this->authenticate($admin);

        $export = $this->get('/api/v1/communication/contacts/'.$contact->id.'/export')->assertOk();
        $payload = json_decode($export->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $picture = data_get($payload, 'contact.identities.0.inbox_profiles.0.profile_picture');
        self::assertIsArray($picture);
        self::assertSame(['state', 'mime_type', 'size_bytes', 'sha256', 'fetched_at', 'retry_at'], array_keys($picture));
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('profile_picture_object_id', $encoded);
        self::assertStringNotContainsString('profile_picture_storage_context', $encoded);
        self::assertStringNotContainsString('provider-private-id', $encoded);
        self::assertStringNotContainsString('https://cdn.example.test', $encoded);

        $this->deleteJson('/api/v1/communication/contacts/'.$contact->id.'/personal-data')->assertOk();
        self::assertFalse(CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->whereKey($profile->id)->exists());
        self::assertTrue(app(CommunicationMediaStore::class)->exists($objectId));
        $intent = CommunicationMediaDeletionIntent::query()->where('object_id', $objectId)->firstOrFail();

        (new DeleteCommunicationMediaObjectJob($objectId, $intent->id))->handle(
            app(CommunicationMediaStore::class),
            app(CommunicationMediaDeletionService::class),
        );
        self::assertFalse(app(CommunicationMediaStore::class)->exists($objectId));
        self::assertNotNull($intent->refresh()->deleted_at);
    }

    public function test_inbox_deletion_records_picture_cleanup_in_the_same_transaction_as_the_cascade(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox removível',
            'is_active' => true,
        ]);
        $identity = $this->identityForContact($tenant, $contact, '+5511999997777');
        $profile = $this->readyProfile($tenant, $inbox, $identity);
        $objectId = (string) $profile->profile_picture_object_id;
        $this->authenticate($admin);

        try {
            DB::transaction(function () use ($inbox): void {
                app(DeleteCommunicationInboxAction::class)->handle($inbox);
                throw new \RuntimeException('rollback-intencional');
            });
            self::fail('A transação externa deveria ter sido revertida.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback-intencional', $error->getMessage());
        }

        self::assertTrue(CommunicationInbox::query()->withoutGlobalScopes()->whereKey($inbox->id)->exists());
        self::assertTrue(CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->whereKey($profile->id)->exists());
        self::assertSame(0, CommunicationMediaDeletionIntent::query()->where('object_id', $objectId)->count());

        $restoredInbox = CommunicationInbox::query()->withoutGlobalScopes()->findOrFail($inbox->id);
        app(DeleteCommunicationInboxAction::class)->handle($restoredInbox);

        self::assertFalse(CommunicationInbox::query()->withoutGlobalScopes()->whereKey($inbox->id)->exists());
        self::assertFalse(CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->whereKey($profile->id)->exists());
        $this->assertDatabaseHas('communication_media_deletion_intents', [
            'tenant_id' => $tenant->id,
            'object_id' => $objectId,
        ]);
    }

    public function test_media_deletion_retry_is_bounded_and_idempotent(): void
    {
        Queue::fake();
        $intent = CommunicationMediaDeletionIntent::query()->create([
            'object_id' => (string) Str::ulid(),
            'due_at' => now(),
        ]);
        $service = app(CommunicationMediaDeletionService::class);

        $service->retry($intent->id, new \RuntimeException('provider detail must not persist'));
        $intent->refresh();
        self::assertSame(1, $intent->attempts);
        self::assertSame('MEDIA_DELETE_FAILED', $intent->last_error_code);
        self::assertTrue($intent->due_at->isFuture());
        self::assertNull($intent->failed_at);

        foreach (range(2, 8) as $attempt) {
            $service->retry($intent->id, new \RuntimeException('provider detail must not persist'));
            self::assertSame($attempt, $intent->refresh()->attempts);
        }
        self::assertNotNull($intent->failed_at);

        $intent->forceFill(['due_at' => now()->subMinute()])->save();
        self::assertSame(0, $service->dispatchDue());
        Queue::assertNotPushed(DeleteCommunicationMediaObjectJob::class);

        $service->retry($intent->id, new \RuntimeException('terminal intent must stay terminal'));
        self::assertSame(8, $intent->refresh()->attempts);

        $intent->forceFill(['deleted_at' => now()])->save();
        $service->retry($intent->id, new \RuntimeException('ignored'));
        self::assertSame(8, $intent->refresh()->attempts);
    }

    public function test_orphan_sweep_advances_past_referenced_objects(): void
    {
        Queue::fake();
        Cache::forget('communication:media-deletion:sweep-cursor:v1');
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $inbox = $this->inbox($tenant);
        $conversation = $this->conversation($tenant, $inbox);
        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => MessageDirection::Inbound,
            'kind' => MessageKind::Image,
            'source' => MessageSource::Gateway,
            'status' => MessageStatus::Delivered,
            'provider_message_id' => 'provider-'.strtolower((string) Str::ulid()),
            'content_digest' => hash('sha256', (string) Str::ulid()),
            'occurred_at' => now(),
        ]);
        $media = app(CommunicationMediaStore::class);
        $root = (string) config('communication.media.disk_root');
        $cutoff = now()->subHours(2)->getTimestamp();

        foreach (range(1, 11) as $index) {
            $metadata = ['tenant_id' => (int) $tenant->id, 'sequence' => $index];
            $stored = $media->putStream(Utils::streamFor('referenced-'.$index), $metadata);
            CommunicationAttachment::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'message_id' => $message->id,
                'object_id' => $stored['object_id'],
                'original_name_encrypted' => 'referenced-'.$index.'.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => $stored['size_bytes'],
                'sha256' => $stored['sha256'],
                'storage_context' => $metadata,
            ]);
            touch($root.'/'.strtolower(substr($stored['object_id'], 0, 2)).'/'.$stored['object_id'].'.media', $cutoff);
        }

        usleep(2_000);
        $orphan = $media->putStream(Utils::streamFor('orphan'), ['tenant_id' => (int) $tenant->id]);
        touch($root.'/'.strtolower(substr($orphan['object_id'], 0, 2)).'/'.$orphan['object_id'].'.media', $cutoff);
        $service = app(CommunicationMediaDeletionService::class);

        self::assertSame(0, $service->sweepOrphans($media, 2, 60));
        self::assertSame(1, $service->sweepOrphans($media, 2, 60));
        $this->assertDatabaseHas('communication_media_deletion_intents', [
            'object_id' => $orphan['object_id'],
        ]);
    }

    public function test_media_cursor_skips_directories_before_its_prefix(): void
    {
        $root = (string) config('communication.media.disk_root');
        $before = '00'.str_repeat('A', 24);
        $cursor = '02'.str_repeat('C', 24);
        $after = '03'.str_repeat('B', 24);
        foreach ([$before, $after] as $objectId) {
            $directory = $root.'/'.strtolower(substr($objectId, 0, 2));
            mkdir($directory, 0700, true);
            file_put_contents($directory.'/'.$objectId.'.media', 'opaque');
            touch($directory.'/'.$objectId.'.media', now()->subHours(2)->getTimestamp());
        }

        $ids = iterator_to_array(
            app(CommunicationMediaStore::class)->oldObjectIds(now()->subHour(), 10, $cursor),
            false,
        );

        self::assertSame([$after], $ids);
    }

    private function readyProfile(Tenant $tenant, CommunicationInbox $inbox, CommunicationIdentity $identity): CommunicationInboxIdentityProfile
    {
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => 1,
        ]);

        return $this->promoteProfile($profile);
    }

    private function promoteProfile(CommunicationInboxIdentityProfile $profile): CommunicationInboxIdentityProfile
    {
        $bytes = "\x89PNG\r\n\x1a\nprofile-picture";
        $context = [
            'tenant_id' => (int) $profile->tenant_id,
            'inbox_id' => (int) $profile->inbox_id,
            'profile_id' => (int) $profile->id,
            'version' => (int) $profile->profile_picture_version,
            'purpose' => 'COMMUNICATION_MEDIA',
        ];
        $stored = app(CommunicationMediaStore::class)->putStream(Utils::streamFor($bytes), $context);
        $profile->forceFill([
            'profile_picture_state' => ProfilePictureState::Ready,
            'profile_picture_object_id' => $stored['object_id'],
            'profile_picture_mime_type' => 'image/png',
            'profile_picture_size_bytes' => $stored['size_bytes'],
            'profile_picture_sha256' => $stored['sha256'],
            'profile_picture_storage_context' => $context,
            'profile_picture_fetched_at' => now(),
        ])->save();

        return $profile;
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function inbox(Tenant $tenant): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Fotos '.Str::ulid(), 'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected, 'is_enabled' => true,
        ]);
    }

    private function member(CommunicationInbox $inbox, User $user): void
    {
        $membershipId = TenantMembership::query()->withoutGlobalScopes()->where('tenant_id', $inbox->tenant_id)->where('user_id', $user->id)->value('id');
        CommunicationInboxMember::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id, 'inbox_id' => $inbox->id, 'tenant_membership_id' => $membershipId, 'is_active' => true,
        ]);
    }

    private function conversation(Tenant $tenant, CommunicationInbox $inbox): CommunicationConversation
    {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Foto', 'is_active' => true]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => '+5511999999999', 'address_hash' => hash('sha256', '+5511999999999'), 'address_masked' => '***9999', 'is_active' => true,
        ]);

        return CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $identity->id,
            'status' => ConversationStatus::Open, 'last_message_at' => now(),
        ]);
    }

    private function identityForContact(Tenant $tenant, CommunicationContact $contact, string $address, ?int $canonicalIdentityId = null): CommunicationIdentity
    {
        return CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'canonical_identity_id' => $canonicalIdentityId,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
    }

    private function conversationForIdentity(Tenant $tenant, CommunicationInbox $inbox, CommunicationIdentity $identity, \DateTimeInterface $lastMessageAt): CommunicationConversation
    {
        return CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $identity->id,
            'status' => ConversationStatus::Open, 'last_message_at' => $lastMessageAt,
        ]);
    }

    private function seedBackfillCandidates(
        Tenant $tenant,
        CommunicationInbox $inbox,
        int $count,
        \DateTimeInterface $lastMessageAt,
    ): void {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Backfill '.$inbox->id,
            'is_active' => true,
        ]);
        $timestamp = now();
        $seed = (string) Str::ulid();
        $identities = [];
        for ($index = 0; $index < $count; $index++) {
            $identities[] = [
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'channel' => CommunicationChannel::Whatsapp->value,
                'address_encrypted' => null,
                'address_hash' => hash('sha256', $seed.':'.$index),
                'address_masked' => '***'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        foreach (array_chunk($identities, 250) as $chunk) {
            DB::table('communication_identities')->insert($chunk);
        }

        $conversations = DB::table('communication_identities')
            ->where('tenant_id', $tenant->id)
            ->where('contact_id', $contact->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (int $identityId): array => [
                'tenant_id' => $tenant->id,
                'inbox_id' => $inbox->id,
                'identity_id' => $identityId,
                'status' => ConversationStatus::Open->value,
                'last_message_at' => $lastMessageAt,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all();
        foreach (array_chunk($conversations, 250) as $chunk) {
            DB::table('communication_conversations')->insert($chunk);
        }
    }

    private function projectedProfile(Tenant $tenant, CommunicationInbox $inbox, CommunicationIdentity $identity, int $version, ?\DateTimeInterface $fetchedAt = null): CommunicationInboxIdentityProfile
    {
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'profile_picture_state' => ProfilePictureState::Pending,
            'profile_picture_version' => $version,
        ]);
        $profile->forceFill([
            'profile_picture_state' => ProfilePictureState::Ready,
            'profile_picture_object_id' => (string) Str::ulid(),
            'profile_picture_mime_type' => 'image/png',
            'profile_picture_size_bytes' => 1,
            'profile_picture_sha256' => str_repeat('a', 64),
            'profile_picture_storage_context' => [
                'tenant_id' => (int) $tenant->id,
                'inbox_id' => (int) $inbox->id,
                'profile_id' => (int) $profile->id,
                'version' => $version,
                'purpose' => 'COMMUNICATION_MEDIA',
            ],
            'profile_picture_fetched_at' => $fetchedAt ?? now(),
        ])->save();

        return $profile;
    }
}

final class ProfilePictureTransport implements CommunicationTransport
{
    public int $queries = 0;

    public ?GatewayQueryData $lastQuery = null;

    /** @param array<string, mixed>|CommunicationTransportException $result */
    public function __construct(private readonly array|CommunicationTransportException $result) {}

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        throw new \LogicException('Não esperado.');
    }

    public function query(GatewayQueryData $query): array
    {
        $this->queries++;
        $this->lastQuery = $query;
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
}

final class ProfilePictureDownloaderFake implements CommunicationProfilePictureDownloader
{
    public int $downloads = 0;

    public function __construct(
        private readonly ?\Closure $beforeReturn = null,
        private readonly ?CommunicationProfilePictureDownloadException $error = null,
    ) {}

    public function download(string $url): DownloadedProfilePicture
    {
        $this->downloads++;
        if ($this->error !== null) {
            throw $this->error;
        }
        ($this->beforeReturn ?? static function (): void {})();
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new \RuntimeException('Não foi possível criar o stream de teste.');
        }
        fwrite($stream, "\x89PNG\r\n\x1a\nfake");
        rewind($stream);

        return new DownloadedProfilePicture($stream, 'image/png', 12);
    }
}
