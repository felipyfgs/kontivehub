<?php

namespace App\Services\Activation;

use App\Domain\Cnpj;
use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\OfficeLifecycleStatus;
use App\Enums\OfficeRole;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\AccountActivation;
use App\Models\Office;
use App\Models\OfficeInstitutionalProfile;
use App\Models\OfficeMembership;
use App\Models\OfficeSubscription;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Usage\CommercialEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria Office + perfil + assinatura pendente + primeiro ADMIN + ativação atomicamente.
 */
final class CreatePendingOfficeService
{
    public function __construct(
        private readonly ActivationCredentialService $credentials,
        private readonly CommercialEntitlementService $commercial,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   name: string,
     *   profile: array{cnpj?: string|null, legal_name: string, institutional_email: string, institutional_phone: string},
     *   plan: SubscriptionPlan|string,
     *   admin_name: string,
     *   admin_email: string,
     *   method: ActivationMethod|string,
     *   idempotency_key: string,
     * }  $input
     * @return array<string, mixed>
     */
    public function create(array $input, User $actor): array
    {
        $plan = $input['plan'] instanceof SubscriptionPlan
            ? $input['plan']
            : SubscriptionPlan::from((string) $input['plan']);

        $method = $input['method'] instanceof ActivationMethod
            ? $input['method']
            : ActivationMethod::from((string) $input['method']);

        $name = trim((string) $input['name']);
        $adminName = trim((string) $input['admin_name']);
        $adminEmail = $this->credentials->normalizeEmail((string) $input['admin_email']);
        $idempotencyKey = trim((string) $input['idempotency_key']);
        $profile = $input['profile'];

        if ($idempotencyKey === '') {
            throw ActivationException::invalid('Chave de idempotência obrigatória.');
        }

        $rawCnpj = trim((string) ($profile['cnpj'] ?? ''));
        $cnpj = $rawCnpj === ''
            ? null
            : Cnpj::parse($rawCnpj)->toStorageString();
        $requestHash = $this->requestHash([
            'name' => $name,
            'profile' => [
                'cnpj' => $cnpj,
                'legal_name' => trim((string) $profile['legal_name']),
                'institutional_email' => $this->credentials->normalizeEmail((string) $profile['institutional_email']),
                'institutional_phone' => trim((string) $profile['institutional_phone']),
            ],
            'plan' => $plan->value,
            'admin_name' => $adminName,
            'admin_email' => $adminEmail,
            'method' => $method->value,
        ]);

        // Replay de idempotência fora da criação.
        $existing = DB::table('office_creation_idempotency')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw ActivationException::conflict(
                    'Chave de idempotência já usada com payload diferente.',
                    'idempotency_payload_mismatch',
                );
            }

            $office = Office::query()
                ->with(['subscription', 'institutionalProfile', 'memberships.user'])
                ->findOrFail($existing->office_id);

            return $this->sanitizedOfficePayload($office, credentialDelivery: 'regeneration_required');
        }

        if (User::query()->where('email', $adminEmail)->exists()) {
            throw ActivationException::emailTaken();
        }

        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();

        $office = DB::transaction(function () use (
            $name,
            $cnpj,
            $profile,
            $plan,
            $adminName,
            $adminEmail,
            $method,
            $issued,
            $expiresAt,
            $actor,
            $idempotencyKey,
            $requestHash,
        ): Office {
            // Re-check e-mail sob lock lógico da tabela users (unique).
            if (User::query()->where('email', $adminEmail)->lockForUpdate()->exists()) {
                throw ActivationException::emailTaken();
            }

            $slug = $this->allocateSlug($name);

            $office = Office::query()->create([
                'name' => $name,
                'slug' => $slug,
                'is_active' => false,
                'lifecycle_status' => OfficeLifecycleStatus::PendingActivation,
            ]);

            // O primeiro PLATFORM_ADMIN nasce antes de existir qualquer Office.
            // Converge o perfil global para o primeiro Office cadastrado sem criar
            // membership tenant nem alterar users.selected_office_id.
            $platformDefaultAssigned = PlatformMembership::query()
                ->where('user_id', $actor->id)
                ->where('role', PlatformRole::PlatformAdmin->value)
                ->where('is_active', true)
                ->whereNull('default_office_id')
                ->update(['default_office_id' => $office->id]) === 1;

            OfficeInstitutionalProfile::query()->create([
                'office_id' => $office->id,
                'cnpj' => $cnpj,
                'legal_name' => trim((string) $profile['legal_name']),
                'institutional_email' => $this->credentials->normalizeEmail((string) $profile['institutional_email']),
                'institutional_phone' => trim((string) $profile['institutional_phone']),
            ]);

            $defaults = $this->commercial->commercialDefaultsForPlan($plan);

            OfficeSubscription::query()->create([
                'office_id' => $office->id,
                'plan' => $plan,
                'status' => SubscriptionStatus::PendingActivation,
                'trial_ends_at' => null,
                'starts_at' => null,
                'ends_at' => null,
                'current_period_starts_at' => null,
                'current_period_ends_at' => null,
                'monthly_api_quota' => $defaults['monthly_api_quota'],
                'commercial_monitor_units' => $defaults['commercial_monitor_units'],
                'max_clients' => $defaults['max_clients'],
                'negotiated_client_limit' => null,
                'max_users' => $defaults['max_users'],
                'limits' => $defaults['limits'],
                'notes' => null,
            ]);

            $user = User::query()->create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => $this->credentials->makeSentinelPasswordHash(),
                'is_active' => false,
                'password_change_required' => true,
            ]);

            $membership = OfficeMembership::query()->create([
                'office_id' => $office->id,
                'user_id' => $user->id,
                'role' => OfficeRole::Admin,
                'is_active' => false,
            ]);

            AccountActivation::query()->create([
                'purpose' => ActivationPurpose::OfficeFirstAdmin,
                'method' => $method,
                'user_id' => $user->id,
                'office_id' => $office->id,
                'office_membership_id' => $membership->id,
                'platform_membership_id' => null,
                'email_normalized' => $adminEmail,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'revoked_at' => null,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            DB::table('office_creation_idempotency')->insert([
                'idempotency_key' => $idempotencyKey,
                'office_id' => $office->id,
                'request_hash' => $requestHash,
                'created_at' => now(),
            ]);

            $this->audit->record(
                action: 'office.pending_created',
                result: 'SUCCESS',
                subject: $office,
                context: [
                    'plan' => $plan->value,
                    'method' => $method->value,
                    'admin_email_masked' => AccountActivation::maskEmail($adminEmail),
                    'lifecycle_status' => OfficeLifecycleStatus::PendingActivation->value,
                    'platform_default_office_assigned' => $platformDefaultAssigned,
                    'platform_office_membership_created' => false,
                ],
                userId: $actor->id,
                officeId: $office->id,
            );

            return $office->fresh(['subscription', 'institutionalProfile', 'memberships.user']);
        });

        return $this->sanitizedOfficePayload(
            $office,
            credentialDelivery: 'delivered',
            secret: $issued,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestHash(array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    private function allocateSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'office';
        }

        $slug = $base;
        $suffix = 0;
        while (Office::query()->where('slug', $slug)->lockForUpdate()->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>|null  $secret
     * @return array<string, mixed>
     */
    public function sanitizedOfficePayload(
        Office $office,
        string $credentialDelivery = 'regeneration_required',
        ?array $secret = null,
        ?string $expiresAt = null,
    ): array {
        $office->loadMissing(['subscription', 'institutionalProfile', 'memberships.user']);

        $firstAdminMembership = $office->memberships
            ->sortBy('id')
            ->first(fn (OfficeMembership $m) => $m->role === OfficeRole::Admin);

        $activation = null;
        if ($firstAdminMembership !== null) {
            $activation = AccountActivation::query()
                ->where('office_membership_id', $firstAdminMembership->id)
                ->where('purpose', ActivationPurpose::OfficeFirstAdmin)
                ->orderByDesc('generation')
                ->orderByDesc('id')
                ->first();
        }

        $data = [
            'office' => [
                'id' => $office->id,
                'name' => $office->name,
                'slug' => $office->slug,
                'is_active' => $office->is_active,
                'lifecycle_status' => $office->lifecycle_status instanceof OfficeLifecycleStatus
                    ? $office->lifecycle_status->value
                    : (string) $office->lifecycle_status,
                'created_at' => $office->created_at?->toIso8601String(),
                'profile' => $office->institutionalProfile?->toPublicArray(),
                'subscription' => $office->subscription?->toSanitizedAdminArray(),
                'first_admin' => $firstAdminMembership === null ? null : [
                    'membership_id' => $firstAdminMembership->id,
                    'user_id' => $firstAdminMembership->user_id,
                    'name' => $firstAdminMembership->user?->name,
                    'email' => $firstAdminMembership->user?->email,
                    'is_active' => $firstAdminMembership->is_active,
                ],
                'activation' => $activation?->toSanitizedArray(),
            ],
            'credential_delivery' => $credentialDelivery,
            'method' => $activation?->method?->value ?? $secret['method'] ?? null,
            'expires_at' => $expiresAt ?? $activation?->expires_at?->toIso8601String(),
        ];

        if ($credentialDelivery === 'delivered' && $secret !== null) {
            if (isset($secret['activation_url'])) {
                $data['activation_url'] = $secret['activation_url'];
            }
            if (isset($secret['temporary_password'])) {
                $data['temporary_password'] = $secret['temporary_password'];
            }
        }

        return $data;
    }
}
