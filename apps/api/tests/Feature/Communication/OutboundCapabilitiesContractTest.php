<?php

namespace Tests\Feature\Communication;

use App\Contracts\GifSearchProvider;
use App\DTO\Communication\OutboundCapabilitiesData;
use App\DTO\Communication\OutboundCapabilityData;
use App\Enums\Communication\InboxStatus;
use App\Enums\TenantRole;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OutboundCapabilitiesContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.media.max_bytes' => 12_345,
        ]);
    }

    public function test_it_exposes_documented_capabilities_and_preserves_compat_fields(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        Sanctum::actingAs($operator);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.requires_permission', 'communication.reply')
            ->assertJsonPath('data.max_media_bytes', 12_345)
            ->assertJsonPath('data.kinds.TEXT.enabled', true)
            ->assertJsonPath('data.kinds.TEXT.family', 'TEXT')
            ->assertJsonPath('data.kinds.TEXT.requires_permission', 'communication.reply')
            ->assertJsonPath('data.kinds.TEXT.supported', true)
            ->assertJsonPath('data.kinds.TEXT.limits.max_text_bytes', 4096)
            ->assertJsonPath('data.kinds.IMAGE.limits.mime_types.0', 'image/jpeg')
            ->assertJsonPath('data.kinds.IMAGE.limits.max_bytes', 12_345)
            ->assertJsonPath('data.kinds.IMAGE.variants.camera.enabled', true)
            ->assertJsonPath('data.kinds.AUDIO.limits.max_duration_seconds', 3600)
            ->assertJsonPath('data.kinds.CONTACT.limits.max_items', 1)
            ->assertJsonPath('data.kinds.CONTACT.multiple', false)
            ->assertJsonPath('data.kinds.POLL.limits.max_options', 12)
            ->assertJsonPath('data.kinds.INTERACTIVE.limits.modes.1', 'LIST')
            ->assertJsonPath('data.kinds.MEDIA_BATCH.enabled', false)
            ->assertJsonPath('data.kinds.MEDIA_BATCH.family', 'MEDIA_BATCH')
            ->assertJsonPath('data.kinds.MEDIA_BATCH.reason', 'ROLLOUT_DISABLED')
            ->assertJsonPath('data.kinds.MEDIA_BATCH.limits.max_items', 10)
            ->assertJsonPath('data.kinds.MEDIA_BATCH.limits.mime_types.5', 'application/pdf')
            ->assertJsonPath('data.kinds.MEDIA_BATCH.variants.album_native.reason', 'NATIVE_ALBUM_INTEROPERABILITY_UNVERIFIED')
            ->assertJsonPath('data.kinds.CONTACT.variants.multiple.reason', 'CONTACTS_ARRAY_BUILDER_UNIMPLEMENTED')
            ->assertJsonPath('data.kinds.VIDEO.variants.gif.reason', 'GIF_PLAYBACK_BUILDER_UNIMPLEMENTED')
            ->assertJsonPath('data.kinds.VIDEO.variants.provider_search.reason', 'GIF_PROVIDER_DISABLED')
            ->assertJsonPath('data.kinds.VIDEO.variants.ptv.reason', 'PTV_BUILDER_UNIMPLEMENTED')
            ->assertJsonPath('data.kinds.IMAGE.variants.view_once.reason', 'VIEW_ONCE_BUILDER_UNIMPLEMENTED')
            ->assertJsonPath('data.kinds.EVENT.reason', 'EVENT_BUILDER_UNIMPLEMENTED')
            ->assertJsonPath('data.kinds.GIF_PROVIDER_SEARCH.reason', 'GIF_PROVIDER_DISABLED')
            ->assertJsonPath('data.kinds.UNSUPPORTED.supported', false)
            ->assertJsonPath('data.kinds.UNSUPPORTED.error_code', 'MESSAGE_KIND_UNSUPPORTED');
    }

    public function test_it_rejects_a_capability_whose_family_does_not_match_its_kind_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OutboundCapabilitiesData(
            enabled: true,
            requiresPermission: 'communication.reply',
            kinds: ['TEXT' => new OutboundCapabilityData('IMAGE', true)],
            maxMediaBytes: 1,
            conversationInitiation: [
                'enabled' => false,
                'reason' => 'rollout_disabled',
                'requires_permission' => 'communication.reply',
            ],
        );
    }

    public function test_it_evaluates_selected_inbox_permission_state_rollout_and_builder_fail_closed(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $inbox = $this->inbox($tenant, [$operator, $viewer]);

        Sanctum::actingAs($operator);
        app(CurrentTenant::class)->clear();
        $this->assertTrue(Gate::forUser($operator)->allows('reply', $inbox));
        $this->getJson('/api/v1/communication/outbound-capabilities?inbox_id='.$inbox->id)
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.kinds.TEXT.enabled', true);

        Sanctum::actingAs($viewer);
        app(CurrentTenant::class)->clear();
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $inbox));
        $this->assertFalse(Gate::forUser($viewer)->allows('reply', $inbox));
        $this->getJson('/api/v1/communication/outbound-capabilities?inbox_id='.$inbox->id)
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.kinds.TEXT.reason', 'PERMISSION_DENIED')
            ->assertJsonPath('data.kinds.TEXT.variants.link_preview.reason', 'PERMISSION_DENIED');

        Sanctum::actingAs($operator);
        app(CurrentTenant::class)->clear();
        $inbox->forceFill(['status' => InboxStatus::Disconnected])->save();
        $this->getJson('/api/v1/communication/outbound-capabilities?inbox_id='.$inbox->id)
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.kinds.TEXT.reason', 'INBOX_DISCONNECTED');

        $inbox->forceFill(['status' => InboxStatus::Connected, 'is_enabled' => false])->save();
        $this->getJson('/api/v1/communication/outbound-capabilities?inbox_id='.$inbox->id)
            ->assertOk()
            ->assertJsonPath('data.kinds.TEXT.reason', 'INBOX_DISABLED');

        config([
            'communication.outbound_builders.event' => true,
            'communication.outbound_features.event' => false,
        ]);
        $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.kinds.EVENT.enabled', false)
            ->assertJsonPath('data.kinds.EVENT.reason', 'ROLLOUT_DISABLED');

        config(['communication.outbound_features.event' => true]);
        $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.kinds.EVENT.enabled', true)
            ->assertJsonPath('data.kinds.EVENT.reason', null);

        config(['communication.outbound_builders.event' => false]);
        $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.kinds.EVENT.enabled', false)
            ->assertJsonPath('data.kinds.EVENT.reason', 'EVENT_BUILDER_UNIMPLEMENTED');
    }

    public function test_optional_gif_provider_is_tenant_authorized_allowlisted_and_proxied(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inbox = $this->inbox($tenant, [$operator]);
        Sanctum::actingAs($operator);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/communication/gifs/search?inbox_id='.$inbox->id.'&q=teste')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'GIF_PROVIDER_DISABLED');

        config([
            'communication.outbound_builders.gif' => true,
            'communication.outbound_features.gif' => true,
            'communication.gif_provider.driver' => 'http',
            'communication.gif_provider.base_url' => 'https://gif.example',
            'communication.gif_provider.api_key' => 'provider-secret',
            'communication.gif_provider.allowed_hosts' => ['cdn.gif.example'],
        ]);
        $this->app->forgetInstance(GifSearchProvider::class);
        Http::fake(function ($request) {
            if ($request->url() === 'https://cdn.gif.example/preview.gif') {
                return Http::response('GIF89a-preview', 200, ['Content-Type' => 'image/gif']);
            }
            if ($request->url() === 'https://cdn.gif.example/media.mp4') {
                return Http::response('gif-video-bytes', 200, [
                    'Content-Type' => 'video/mp4',
                    'Content-Length' => '15',
                ]);
            }

            return Http::response(['data' => [
                [
                    'id' => 'gif-1',
                    'title' => 'Resultado',
                    'preview_url' => 'https://cdn.gif.example/preview.gif',
                    'media_url' => 'https://cdn.gif.example/media.mp4',
                ],
                [
                    'id' => 'evil',
                    'preview_url' => 'https://evil.example/track.gif',
                    'media_url' => 'https://evil.example/media.mp4',
                ],
            ]]);
        });

        $search = $this->getJson('/api/v1/communication/gifs/search?inbox_id='.$inbox->id.'&q=teste&limit=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'gif-1');
        $this->assertStringNotContainsString('gif.example', $search->json('data.0.preview_path'));
        $this->assertStringNotContainsString('gif.example', $search->json('data.0.asset_path'));
        $this->assertArrayNotHasKey('media_url', $search->json('data.0'));

        $this->get($search->json('data.0.preview_path'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif')
            ->assertSee('GIF89a-preview');
        $asset = $this->get($search->json('data.0.asset_path'))
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('gif-video-bytes');
        $cacheDirectives = array_map('trim', explode(',', (string) $asset->headers->get('Cache-Control')));
        $this->assertContains('private', $cacheDirectives);
        $this->assertContains('no-store', $cacheDirectives);
    }

    public function test_gif_asset_is_bound_to_the_original_reply_authorized_inbox_and_validated_before_streaming(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $inbox = $this->inbox($tenant, [$operator, $viewer]);
        Sanctum::actingAs($operator);
        app(CurrentTenant::class)->clear();
        config([
            'communication.media.max_bytes' => 12,
            'communication.gif_provider.allowed_hosts' => ['cdn.gif.example'],
        ]);
        $token = str_repeat('a', 40);
        Cache::put('communication:gif-asset:'.$tenant->id.':'.$token, [
            'inbox_id' => $inbox->id,
            'media_url' => 'https://cdn.gif.example/media.mp4',
        ], now()->addMinute());

        Http::fake(['https://cdn.gif.example/media.mp4' => Http::response('too-large-bytes', 200, [
            'Content-Type' => 'video/mp4',
            'Content-Length' => '15',
        ])]);
        $this->get('/api/v1/communication/gifs/'.$token.'/asset')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'GIF_ASSET_UNAVAILABLE');

        Cache::put('communication:gif-asset:'.$tenant->id.':'.$token, [
            'inbox_id' => $inbox->id,
            'media_url' => 'https://evil.example/media.mp4',
        ], now()->addMinute());
        $this->get('/api/v1/communication/gifs/'.$token.'/asset')->assertNotFound();

        Cache::put('communication:gif-asset:'.$tenant->id.':'.$token, [
            'inbox_id' => $inbox->id,
            'media_url' => 'https://cdn.gif.example/media.mp4',
        ], now()->addMinute());
        Sanctum::actingAs($viewer);
        app(CurrentTenant::class)->clear();
        $this->get('/api/v1/communication/gifs/'.$token.'/asset')->assertNotFound();

        Sanctum::actingAs($operator);
        app(CurrentTenant::class)->clear();
        Http::fake(['https://cdn.gif.example/media.mp4' => Http::response('invalid', 200, [
            'Content-Type' => 'application/octet-stream',
        ])]);
        $this->get('/api/v1/communication/gifs/'.$token.'/asset')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'GIF_ASSET_UNAVAILABLE');
    }

    public function test_gif_preview_requires_view_access_to_its_original_inbox(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $viewerOutsideInbox = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $inbox = $this->inbox($tenant, [$operator]);
        $token = str_repeat('p', 40);
        Cache::put('communication:gif-asset:'.$tenant->id.':'.$token, [
            'inbox_id' => $inbox->id,
            'preview_url' => 'https://cdn.gif.example/preview.gif',
        ], now()->addMinute());
        config(['communication.gif_provider.allowed_hosts' => ['cdn.gif.example']]);

        Sanctum::actingAs($viewerOutsideInbox);
        app(CurrentTenant::class)->clear();
        Http::fake(['https://cdn.gif.example/preview.gif' => Http::response('GIF89a-preview', 200, [
            'Content-Type' => 'image/gif',
        ])]);
        $this->get('/api/v1/communication/gifs/'.$token.'/preview')->assertNotFound();
        Http::assertNothingSent();
    }

    /** @param list<User> $members */
    private function inbox(Tenant $tenant, array $members): CommunicationInbox
    {
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Capabilities',
            'session_id' => 'session-'.Str::ulid(),
            'address_encrypted' => '+5511000000088',
            'address_hash' => hash('sha256', '+5511000000088'),
            'address_masked' => '***0088',
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
        foreach ($members as $member) {
            $membership = TenantMembership::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $member->id)
                ->sole();
            CommunicationInboxMember::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'inbox_id' => $inbox->id,
                'tenant_membership_id' => $membership->id,
                'is_active' => true,
            ]);
        }

        return $inbox;
    }
}
