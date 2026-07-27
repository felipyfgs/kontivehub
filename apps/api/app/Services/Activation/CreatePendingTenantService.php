<?php

namespace App\Services\Activation;

use App\Domain\Cnpj;
use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Models\AccountActivation;
use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\TenantInstitutionalProfile;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Usage\CommercialEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria Tenant + perfil + assinatura pendente + primeiro ADMIN + ativação atomicamente.
 */
final class CreatePendingTenantService
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
        $existing = DB::table('tenant_creation_idempotency')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw ActivationException::conflict(
                    'Chave de idempotência já usada com payload diferente.',
                    'idempotency_payload_mismatch',
                );
            }

            $tenant = Tenant::query()
                ->with(['subscription', 'institutionalProfile', 'memberships.user'])
                ->findOrFail($existing->tenant_id);

            return $this->sanitizedTenantPayload($tenant, credentialDelivery: 'regeneration_required');
        }

        if (User::query()->where('email', $adminEmail)->exists()) {
            throw ActivationException::emailTaken();
        }

        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();

        $tenant = DB::transaction(function () use (
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
        ): Tenant {
            // Re-check e-mail sob lock lógico da tabela users (unique).
            if (User::query()->where('email', $adminEmail)->lockForUpdate()->exists()) {
                throw ActivationException::emailTaken();
            }

            $slug = $this->allocateSlug($name);

            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'is_active' => false,
                'lifecycle_status' => TenantLifecycleStatus::PendingActivation,
            ]);

            // O primeiro PLATFORM_ADMIN nasce antes de existir qualquer Tenant.
            // Converge o perfil global para o primeiro Tenant cadastrado sem criar
            // membership tenant nem alterar users.selected_tenant_id.
            $platformDefaultAssigned = PlatformMembership::query()
                ->where('user_id', $actor->id)
                ->where('role', PlatformRole::PlatformAdmin->value)
                ->where('is_active', true)
                ->whereNull('default_tenant_id')
                ->update(['default_tenant_id' => $tenant->id]) === 1;

            TenantInstitutionalProfile::query()->create([
                'tenant_id' => $tenant->id,
                'cnpj' => $cnpj,
                'legal_name' => trim((string) $profile['legal_name']),
                'institutional_email' => $this->credentials->normalizeEmail((string) $profile['institutional_email']),
                'institutional_phone' => trim((string) $profile['institutional_phone']),
            ]);

            $defaults = $this->commercial->commercialDefaultsForPlan($plan);

            TenantSubscription::query()->create([
                'tenant_id' => $tenant->id,
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

            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => TenantRole::TenantAdmin,
                'is_active' => false,
            ]);

            AccountActivation::query()->create([
                'purpose' => ActivationPurpose::TenantFirstAdmin,
                'method' => $method,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'tenant_membership_id' => $membership->id,
                'platform_membership_id' => null,
                'email_normalized' => $adminEmail,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'revoked_at' => null,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            DB::table('tenant_creation_idempotency')->insert([
                'idempotency_key' => $idempotencyKey,
                'tenant_id' => $tenant->id,
                'request_hash' => $requestHash,
                'created_at' => now(),
            ]);

            $this->audit->record(
                action: 'tenant.pending_created',
                result: 'SUCCESS',
                subject: $tenant,
                context: [
                    'plan' => $plan->value,
                    'method' => $method->value,
                    'admin_email_masked' => AccountActivation::maskEmail($adminEmail),
                    'lifecycle_status' => TenantLifecycleStatus::PendingActivation->value,
                    'platform_default_tenant_assigned' => $platformDefaultAssigned,
                    'platform_tenant_membership_created' => false,
                ],
                userId: $actor->id,
                tenantId: $tenant->id,
            );

            return $tenant->fresh(['subscription', 'institutionalProfile', 'memberships.user']);
        });

        return $this->sanitizedTenantPayload(
            $tenant,
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
            $base = 'tenant';
        }

        $slug = $base;
        $suffix = 0;
        while (Tenant::query()->where('slug', $slug)->lockForUpdate()->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>|null  $secret
     * @return array<string, mixed>
     */
    public function sanitizedTenantPayload(
        Tenant $tenant,
        string $credentialDelivery = 'regeneration_required',
        ?array $secret = null,
        ?string $expiresAt = null,
    ): array {
        $tenant->loadMissing(['subscription', 'institutionalProfile', 'memberships.user']);

        $firstAdminMembership = $tenant->memberships
            ->sortBy('id')
            ->first(fn (TenantMembership $m) => $m->role === TenantRole::TenantAdmin);

        $activation = null;
        if ($firstAdminMembership !== null) {
            $activation = AccountActivation::query()
                ->where('tenant_membership_id', $firstAdminMembership->id)
                ->where('purpose', ActivationPurpose::TenantFirstAdmin)
                ->orderByDesc('generation')
                ->orderByDesc('id')
                ->first();
        }

        $data = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'is_active' => $tenant->is_active,
                'lifecycle_status' => $tenant->lifecycle_status instanceof TenantLifecycleStatus
                    ? $tenant->lifecycle_status->value
                    : (string) $tenant->lifecycle_status,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'profile' => $tenant->institutionalProfile?->toPublicArray(),
                'subscription' => $tenant->subscription?->toSanitizedAdminArray(),
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
