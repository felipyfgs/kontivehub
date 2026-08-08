<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\StickerAvailability;
use App\Enums\Communication\StickerSource;
use App\Enums\CommunicationChannel;
use App\Enums\TenantRole;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationStickerContent;
use App\Models\CommunicationStickerObservation;
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

final class StickerLibraryApiTest extends TestCase
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
            'communication.sticker_library.enabled' => true,
            'communication.sticker_library.device_sync_enabled' => false,
        ]);
    }

    public function test_import_list_favorite_preview_and_library_send_are_tenant_scoped(): void
    {
        [$conversation, $inbox] = $this->context();
        $webp = $this->webpBytes();

        $imported = $this->post(
            '/api/v1/communication/inboxes/'.$inbox->id.'/stickers/import',
            ['file' => UploadedFile::fake()->createWithContent('aceno.webp', $webp)],
            ['Accept' => 'application/json'],
        )
            ->assertCreated()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.source', StickerSource::LocalImport->value);
        $stickerId = (string) $imported->json('data.id');
        $this->assertNotSame('', $stickerId);

        $this->getJson('/api/v1/communication/inboxes/'.$inbox->id.'/stickers')
            ->assertOk()
            ->assertJsonPath('meta.sync_status', 'not_observed')
            ->assertJsonPath('data.0.id', $stickerId)
            ->assertJsonPath('data.0.available', true);

        $this->putJson('/api/v1/communication/stickers/'.$stickerId.'/favorite', ['favorite' => true])
            ->assertOk()
            ->assertJsonPath('data.app_favorite', true)
            ->assertJsonPath('data.device_favorite', false);

        $preview = $this->get('/api/v1/communication/stickers/'.$stickerId.'/preview')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $cacheControl = strtolower((string) $preview->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $this->post(
            '/api/v1/communication/conversations/'.$conversation->id.'/messages',
            [
                'kind' => 'STICKER',
                'library_sticker_id' => $stickerId,
                'idempotency_key' => 'library-sticker-send-0001',
            ],
            ['Accept' => 'application/json'],
        )
            ->assertAccepted()
            ->assertJsonPath('data.kind', 'STICKER');

        $this->post(
            '/api/v1/communication/conversations/'.$conversation->id.'/messages',
            [
                'kind' => 'STICKER',
                'library_sticker_id' => $stickerId,
                'idempotency_key' => 'library-sticker-send-0001',
            ],
            ['Accept' => 'application/json'],
        )
            ->assertOk()
            ->assertJsonPath('data.kind', 'STICKER');

        $this->assertDatabaseCount('communication_messages', 1);
        $this->assertTrue(
            CommunicationStickerContent::query()->where('retention_protected', true)->exists(),
        );

        $foreign = $this->foreignTenantContext();
        Sanctum::actingAs($foreign['user']);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/communication/inboxes/'.$inbox->id.'/stickers')
            ->assertNotFound();
        $this->get('/api/v1/communication/stickers/'.$stickerId.'/preview')
            ->assertNotFound();
        $this->post(
            '/api/v1/communication/conversations/'.$conversation->id.'/messages',
            [
                'kind' => 'STICKER',
                'library_sticker_id' => $stickerId,
                'idempotency_key' => 'foreign-library-sticker-0001',
            ],
            ['Accept' => 'application/json'],
        )->assertNotFound();
    }

    public function test_import_rejects_non_webp_and_deduplicates_bytes(): void
    {
        [, $inbox] = $this->context();
        $webp = $this->webpBytes();

        $this->post(
            '/api/v1/communication/inboxes/'.$inbox->id.'/stickers/import',
            ['file' => UploadedFile::fake()->createWithContent('x.png', 'not-webp')],
            ['Accept' => 'application/json'],
        )->assertUnprocessable();

        $first = $this->post(
            '/api/v1/communication/inboxes/'.$inbox->id.'/stickers/import',
            ['file' => UploadedFile::fake()->createWithContent('a.webp', $webp)],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $second = $this->post(
            '/api/v1/communication/inboxes/'.$inbox->id.'/stickers/import',
            ['file' => UploadedFile::fake()->createWithContent('b.webp', $webp)],
            ['Accept' => 'application/json'],
        )->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('communication_sticker_contents', 1);
        $this->assertDatabaseCount('communication_sticker_observations', 1);
    }

    public function test_cleanup_dry_run_skips_favorites_and_protected_content(): void
    {
        [, $inbox] = $this->context();
        $tenantId = (int) $inbox->tenant_id;
        $content = CommunicationStickerContent::query()->create([
            'tenant_id' => $tenantId,
            'sha256' => hash('sha256', 'expired-sticker'),
            'object_id_encrypted' => (string) Str::ulid(),
            'storage_context_encrypted' => ['tenant_id' => $tenantId, 'inbox_id' => $inbox->id],
            'mime_type' => 'image/webp',
            'size_bytes' => 32,
            'width' => 32,
            'height' => 32,
            'animated' => false,
            'provenance' => StickerSource::DeviceRecent,
            'retention_protected' => false,
            'expires_at' => now()->subDay(),
        ]);
        CommunicationStickerObservation::query()->create([
            'tenant_id' => $tenantId,
            'inbox_id' => $inbox->id,
            'content_id' => $content->id,
            'observation_id' => 'obs:'.Str::ulid(),
            'source' => StickerSource::DeviceRecent,
            'availability' => StickerAvailability::Available,
            'app_favorite' => true,
            'last_observed_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('communication:cleanup-sticker-library', ['--dry-run' => true])
            ->assertSuccessful();
        $this->assertDatabaseCount('communication_sticker_contents', 1);
        $this->assertDatabaseHas('communication_sticker_observations', [
            'content_id' => $content->id,
            'app_favorite' => true,
            'removed_at' => null,
        ]);
    }

    /** @return array{0: CommunicationConversation, 1: CommunicationInbox} */
    private function context(string $permissionProfile = 'operator'): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser, $permissionProfile)->create();
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Stickers',
            'session_id' => 'session-'.Str::ulid(),
            'address_encrypted' => '+5511000000099',
            'address_hash' => hash('sha256', '+5511000000099'),
            'address_masked' => '***0099',
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
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();

        return [$conversation, $inbox];
    }

    /** @return array{user: User, tenant: Tenant} */
    private function foreignTenantContext(): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'operator')->create();

        return ['user' => $user, 'tenant' => $tenant];
    }

    private function webpBytes(): string
    {
        $webp = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEALmk0mk0iIiIiIgBoSygABc6zbAAA', true);
        $this->assertIsString($webp);

        return $webp;
    }
}
