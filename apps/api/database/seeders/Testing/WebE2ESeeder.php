<?php

namespace Database\Seeders\Testing;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\ProfilePictureState;
use App\Enums\CommunicationChannel;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\CommunicationMessage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Baseline mínimo para Playwright local (login Sanctum + Communication).
 *
 * Credenciais estáveis:
 * - admin@kontivehub.local / password (tenant_admin)
 * - operador@example.com / password (tenant_admin; specs legados)
 *
 * Dataset real e idempotente de Communication para os cenários Playwright.
 */
final class WebE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('WebE2ESeeder permitido somente em local/testing.');
        }

        DB::transaction(function (): void {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => 'e2e'],
                [
                    'name' => 'Tenant E2E',
                    'is_active' => true,
                    'lifecycle_status' => TenantLifecycleStatus::Active,
                    'timezone' => 'America/Sao_Paulo',
                    'communication_enabled' => true,
                ],
            );

            $plan = SubscriptionPlan::Professional;
            $limits = $plan->defaultLimits();
            $commercial = $plan->commercialEntitlements();
            TenantSubscription::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan' => $plan,
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => '2026-01-01 00:00:00',
                    'current_period_starts_at' => '2026-01-01 00:00:00',
                    'current_period_ends_at' => '2099-12-31 23:59:59',
                    'monthly_api_quota' => $limits['monthly_api_quota'],
                    'commercial_monitor_units' => $commercial['commercial_monitor_units'],
                    'max_clients' => $limits['max_clients'],
                    'max_users' => $limits['max_users'],
                    'limits' => [...$limits, ...$commercial],
                ],
            );

            $this->upsertTenantAdmin(
                $tenant,
                'admin@kontivehub.local',
                'Administrador E2E',
            );
            $this->upsertTenantAdmin(
                $tenant,
                'operador@example.com',
                'Operador E2E',
            );

            $this->seedCommunication($tenant);
        });
    }

    private function upsertTenantAdmin(Tenant $tenant, string $email, string $name): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => 'password',
            'email_verified_at' => '2026-01-01 00:00:00',
            'is_active' => true,
            'password_change_required' => false,
            'selected_tenant_id' => $tenant->id,
        ])->save();

        TenantMembership::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'role' => TenantRole::TenantAdmin,
                'permission_profile_id' => null,
                'authorization_version' => 1,
                'is_active' => true,
            ],
        );
    }

    private function seedCommunication(Tenant $tenant): void
    {
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Atendimento E2E'],
            [
                'session_id' => 'web-e2e-whatsapp',
                'status' => InboxStatus::Connected,
                'is_enabled' => true,
                'is_default' => true,
                'connected_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        $this->seedCommunicationContact(
            tenant: $tenant,
            inbox: $inbox,
            name: 'Cliente E2E com foto',
            phone: '+5511999902709',
            message: 'Mensagem real E2E com foto.',
            readyPicture: true,
        );
        $this->seedCommunicationContact(
            tenant: $tenant,
            inbox: $inbox,
            name: 'Cliente E2E sem foto',
            phone: '+5511999902710',
            message: 'Mensagem real E2E sem foto.',
            readyPicture: false,
        );
    }

    private function seedCommunicationContact(
        Tenant $tenant,
        CommunicationInbox $inbox,
        string $name,
        string $phone,
        string $message,
        bool $readyPicture,
    ): void {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $name],
            ['is_provisional' => false, 'is_active' => true],
        );
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'channel' => CommunicationChannel::WhatsApp,
                'address_hash' => hash('sha256', $phone),
            ],
            [
                'contact_id' => $contact->id,
                'address_encrypted' => $phone,
                'address_masked' => '+55••••'.substr($phone, -4),
                'is_active' => true,
                'last_seen_at' => now(),
            ],
        );
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $identity->id],
            [
                'status' => ConversationStatus::Open,
                'priority' => 0,
                'last_message_at' => now(),
                'lock_version' => 1,
            ],
        );
        CommunicationMessage::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'provider_message_id' => 'web-e2e-'.hash('sha256', $phone)],
            [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'identity_id' => $identity->id,
                'direction' => MessageDirection::Inbound,
                'kind' => MessageKind::Text,
                'source' => MessageSource::Gateway,
                'status' => MessageStatus::Delivered,
                'body_encrypted' => $message,
                'content_encrypted' => ['text' => $message],
                'occurred_at' => now(),
                'delivered_at' => now(),
            ],
        );

        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'inbox_id' => $inbox->id, 'identity_id' => $identity->id],
            [
                'field_versions' => [],
                'cleared_fields' => [],
                'profile_picture_state' => ProfilePictureState::Unknown,
                'profile_picture_version' => 1,
            ],
        );

        if (! $readyPicture) {
            $profile->forceFill([
                'profile_picture_state' => ProfilePictureState::Unavailable,
                'profile_picture_object_id' => null,
                'profile_picture_mime_type' => null,
                'profile_picture_size_bytes' => null,
                'profile_picture_sha256' => null,
                'profile_picture_storage_context' => null,
                'profile_picture_fetched_at' => now(),
            ])->save();

            return;
        }

        $media = app(MediaStore::class);
        if ($profile->profile_picture_state === ProfilePictureState::Ready
            && is_string($profile->profile_picture_object_id)
            && $media->exists($profile->profile_picture_object_id)) {
            return;
        }

        $profile->forceFill(['profile_picture_version' => max(1, (int) $profile->profile_picture_version)])->save();
        $context = [
            'tenant_id' => (int) $tenant->id,
            'inbox_id' => (int) $inbox->id,
            'profile_id' => (int) $profile->id,
            'version' => (int) $profile->profile_picture_version,
            'purpose' => 'COMMUNICATION_MEDIA',
        ];
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        rewind($stream);
        $stored = $media->putStream($stream, $context);
        fclose($stream);
        $profile->forceFill([
            'profile_picture_state' => ProfilePictureState::Ready,
            'profile_picture_object_id' => $stored['object_id'],
            'profile_picture_mime_type' => 'image/png',
            'profile_picture_size_bytes' => $stored['size_bytes'],
            'profile_picture_sha256' => $stored['sha256'],
            'profile_picture_storage_context' => $context,
            'profile_picture_fetched_at' => now(),
            'profile_picture_retry_at' => null,
            'profile_picture_error_code' => null,
        ])->save();
    }
}
