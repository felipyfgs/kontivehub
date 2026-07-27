<?php

namespace App\Services\Activation;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\TenantRole;
use App\Models\AccountActivation;
use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\SystemTenantPermissionProfiles;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Gestão de equipe do Tenant — TenantMembership ADMIN real no CurrentTenant,
 * ou PLATFORM_ADMIN em contexto privilegiado com tenant resolvido.
 */
final class TenantTeamService
{
    public function __construct(
        private readonly ActivationCredentialService $credentials,
        private readonly RegenerateActivationService $regenerate,
        private readonly CorrectPendingRecipientService $correctRecipient,
        private readonly CurrentTenant $currentTenant,
        private readonly AuditLogger $audit,
        private readonly SystemTenantPermissionProfiles $systemProfiles,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor): array
    {
        $tenant = $this->assertRealAdmin($actor);

        return TenantMembership::query()
            ->with('user')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get()
            ->map(fn (TenantMembership $m) => $this->sanitizeMembership($m))
            ->all();
    }

    /**
     * @param  array{
     *   name: string,
     *   email: string,
     *   role: TenantRole|string,
     *   method: ActivationMethod|string
     * }  $input
     * @return array<string, mixed>
     */
    public function createMember(User $actor, array $input): array
    {
        $tenant = $this->assertRealAdmin($actor);

        $role = $input['role'] instanceof TenantRole
            ? $input['role']
            : TenantRole::from((string) $input['role']);

        $method = $input['method'] instanceof ActivationMethod
            ? $input['method']
            : ActivationMethod::from((string) $input['method']);
        $permissionProfileId = $role === TenantRole::TenantUser
            ? $this->systemProfiles->ensure($tenant)['operator']->id
            : null;

        $name = trim((string) $input['name']);
        $email = $this->credentials->normalizeEmail((string) $input['email']);

        if (User::query()->where('email', $email)->exists()) {
            throw ActivationException::emailTaken();
        }

        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();

        $result = DB::transaction(function () use ($tenant, $name, $email, $role, $permissionProfileId, $method, $issued, $expiresAt, $actor) {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $subscription = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                throw ActivationException::invalid('Assinatura do escritório não encontrada.');
            }

            $this->assertSeatAvailable($tenant, $subscription);

            if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                throw ActivationException::emailTaken();
            }

            // Bloqueia se e-mail já existisse com qualquer grant (unique em email cobre).
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $this->credentials->makeSentinelPasswordHash(),
                'is_active' => false,
                'password_change_required' => true,
            ]);

            $membership = TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => $role,
                'permission_profile_id' => $permissionProfileId,
                'is_active' => false,
            ]);

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::TenantMember,
                'method' => $method,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'tenant_membership_id' => $membership->id,
                'platform_membership_id' => null,
                'email_normalized' => $email,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'tenant.member_created',
                result: 'SUCCESS',
                subject: $membership,
                context: [
                    'role' => $role->value,
                    'method' => $method->value,
                    'email_masked' => AccountActivation::maskEmail($email),
                ],
                userId: $actor->id,
                tenantId: $tenant->id,
            );

            return [$membership->load('user'), $activation];
        });

        /** @var TenantMembership $membership */
        /** @var AccountActivation $activation */
        [$membership, $activation] = $result;

        $payload = [
            'membership' => $this->sanitizeMembership($membership, $activation),
            'credential_delivery' => 'delivered',
            'method' => $method->value,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        if (isset($issued['activation_url'])) {
            $payload['activation_url'] = $issued['activation_url'];
        }
        if (isset($issued['temporary_password'])) {
            $payload['temporary_password'] = $issued['temporary_password'];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function changeRole(User $actor, TenantMembership $membership, TenantRole $role): array
    {
        $tenant = $this->assertRealAdmin($actor);
        $this->assertMembershipInTenant($membership, $tenant);

        return DB::transaction(function () use ($membership, $role, $tenant, $actor) {
            /** @var TenantMembership $locked */
            $locked = TenantMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->role === TenantRole::TenantAdmin && $role !== TenantRole::TenantAdmin && $locked->is_active) {
                $this->assertNotLastActiveAdmin($tenant, $locked->id);
            }

            $from = $locked->role->value;
            $permissionProfileId = $role === TenantRole::TenantUser
                ? $this->systemProfiles->ensure($tenant)['operator']->id
                : null;
            $locked->forceFill([
                'role' => $role,
                'permission_profile_id' => $permissionProfileId,
            ])->save();

            $this->audit->record(
                action: 'tenant.member_role_changed',
                result: 'SUCCESS',
                subject: $locked,
                context: [
                    'from_role' => $from,
                    'to_role' => $role->value,
                ],
                userId: $actor->id,
                tenantId: $tenant->id,
            );

            return $this->sanitizeMembership($locked->load('user'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(User $actor, TenantMembership $membership): array
    {
        $tenant = $this->assertRealAdmin($actor);
        $this->assertMembershipInTenant($membership, $tenant);

        return DB::transaction(function () use ($membership, $tenant, $actor) {
            /** @var TenantMembership $locked */
            $locked = TenantMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->role === TenantRole::TenantAdmin && $locked->is_active) {
                $this->assertNotLastActiveAdmin($tenant, $locked->id);
            }

            $locked->forceFill(['is_active' => false])->save();

            AccountActivation::query()
                ->where('tenant_membership_id', $locked->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (AccountActivation $a) => $a->forceFill(['revoked_at' => now()])->save());

            /** @var User $user */
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $this->revokeUserSessions($user);

            if (! $this->userHasOtherActiveGrant($user, $locked->id)) {
                $user->forceFill(['is_active' => false])->save();
            }

            $this->audit->record(
                action: 'tenant.member_deactivated',
                result: 'SUCCESS',
                subject: $locked,
                context: [
                    'user_id' => $user->id,
                    'user_global_deactivated' => ! $user->is_active,
                ],
                userId: $actor->id,
                tenantId: $tenant->id,
            );

            return $this->sanitizeMembership($locked->load('user'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivate(
        User $actor,
        TenantMembership $membership,
        ?ActivationMethod $method = null,
    ): array {
        $tenant = $this->assertRealAdmin($actor);
        $this->assertMembershipInTenant($membership, $tenant);

        $method ??= ActivationMethod::ManualLink;

        return DB::transaction(function () use ($membership, $tenant, $actor, $method) {
            /** @var TenantMembership $locked */
            $locked = TenantMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active) {
                throw ActivationException::conflict('Membership já está ativa.', 'already_active');
            }

            $subscription = TenantSubscription::query()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Contagem: reativação ocupará vaga se ainda não conta (desativada).
            $this->assertSeatAvailable($tenant, $subscription);

            /** @var User $user */
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            $hasOtherActive = $this->userHasOtherActiveGrant($user, $locked->id);

            if ($hasOtherActive) {
                // A conta já está autenticável por outro vínculo ativo.
                $locked->forceFill(['is_active' => true])->save();
                if (! $user->is_active) {
                    $user->forceFill(['is_active' => true])->save();
                }

                $this->audit->record(
                    action: 'tenant.member_reactivated_immediate',
                    result: 'SUCCESS',
                    subject: $locked,
                    context: ['user_id' => $user->id],
                    userId: $actor->id,
                    tenantId: $tenant->id,
                );

                return [
                    'membership' => $this->sanitizeMembership($locked->load('user')),
                    'credential_delivery' => 'not_required',
                    'immediate' => true,
                ];
            }

            // Sem outro grant: nova ativação obrigatória; membership/user ficam inativos até conclusão.
            $issued = $this->credentials->issueSecret($method);
            $expiresAt = $this->credentials->expiresAtFor();

            AccountActivation::query()
                ->where('tenant_membership_id', $locked->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (AccountActivation $a) => $a->forceFill(['revoked_at' => now()])->save());

            $nextGeneration = (int) AccountActivation::query()
                ->where('tenant_membership_id', $locked->id)
                ->max('generation') + 1;

            $user->forceFill([
                'is_active' => false,
                'password_change_required' => true,
                'password' => $this->credentials->makeSentinelPasswordHash(),
            ])->save();

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::TenantMember,
                'method' => $method,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'tenant_membership_id' => $locked->id,
                'platform_membership_id' => null,
                'email_normalized' => $this->credentials->normalizeEmail($user->email),
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => max(1, $nextGeneration),
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'tenant.member_reactivated_pending',
                result: 'SUCCESS',
                subject: $locked,
                context: [
                    'user_id' => $user->id,
                    'method' => $method->value,
                    'generation' => $activation->generation,
                ],
                userId: $actor->id,
                tenantId: $tenant->id,
            );

            $payload = [
                'membership' => $this->sanitizeMembership($locked->load('user'), $activation),
                'credential_delivery' => 'delivered',
                'immediate' => false,
                'method' => $method->value,
                'expires_at' => $expiresAt->toIso8601String(),
            ];

            if (isset($issued['activation_url'])) {
                $payload['activation_url'] = $issued['activation_url'];
            }
            if (isset($issued['temporary_password'])) {
                $payload['temporary_password'] = $issued['temporary_password'];
            }

            return $payload;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function regenerateActivation(
        User $actor,
        TenantMembership $membership,
        ActivationMethod $method,
    ): array {
        $tenant = $this->assertRealAdmin($actor);
        $this->assertMembershipInTenant($membership, $tenant);

        $activation = AccountActivation::query()
            ->where('tenant_membership_id', $membership->id)
            ->whereNull('consumed_at')
            ->orderByDesc('generation')
            ->orderByDesc('id')
            ->first();

        if ($activation === null) {
            throw ActivationException::notFound('Nenhuma ativação pendente para regenerar.');
        }

        if ($activation->isConsumed()) {
            throw ActivationException::invalid('Ativação já consumida.');
        }

        return $this->regenerate->regenerate($activation, $method, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function correctRecipient(
        User $actor,
        TenantMembership $membership,
        string $name,
        string $email,
        ActivationMethod $method,
    ): array {
        $tenant = $this->assertRealAdmin($actor);
        $this->assertMembershipInTenant($membership, $tenant);

        return $this->correctRecipient->correctTenantMember($membership, $name, $email, $method, $actor);
    }

    /**
     * Contagem de vagas: ativas + pendentes (com ativação válida); desativadas não contam.
     */
    public function occupiedSeats(Tenant $tenant): int
    {
        $memberships = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'is_active']);

        $count = 0;
        foreach ($memberships as $m) {
            if ($m->is_active) {
                $count++;

                continue;
            }

            $pending = AccountActivation::query()
                ->where('tenant_membership_id', $m->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($pending) {
                $count++;
            }
        }

        return $count;
    }

    private function assertSeatAvailable(Tenant $tenant, TenantSubscription $subscription): void
    {
        $max = (int) ($subscription->max_users ?? 0);
        if ($max <= 0) {
            throw ActivationException::seatLimit('Limite de usuários não configurado.');
        }

        if ($this->occupiedSeats($tenant) >= $max) {
            throw ActivationException::seatLimit();
        }
    }

    private function assertNotLastActiveAdmin(Tenant $tenant, int $exceptMembershipId): void
    {
        $otherAdmins = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', TenantRole::TenantAdmin)
            ->where('is_active', true)
            ->where('id', '!=', $exceptMembershipId)
            ->count();

        if ($otherAdmins === 0) {
            throw ActivationException::forbidden('Não é possível rebaixar ou desativar o último ADMIN ativo.');
        }
    }

    private function assertRealAdmin(User $actor): Tenant
    {
        $tenant = $this->currentTenant->resolve($actor);
        if ($tenant === null) {
            throw ActivationException::forbidden('Contexto de escritório obrigatório.');
        }

        // Proprietário da instalação atuando no tenant selecionado (seletor global).
        if ($this->currentTenant->isPlatformPrivileged() && $actor->isPlatformAdmin()) {
            return $tenant;
        }

        if (! $this->currentTenant->hasRealMembership()) {
            throw ActivationException::forbidden('Gestão de equipe exige membership ADMIN real no escritório.');
        }

        $role = $this->currentTenant->realTenantRole();
        if ($role !== TenantRole::TenantAdmin) {
            throw ActivationException::forbidden('Somente ADMIN do escritório pode gerir a equipe.');
        }

        // Membership real ativa no tenant.
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->where('role', TenantRole::TenantAdmin)
            ->first();

        if ($membership === null) {
            throw ActivationException::forbidden('Somente ADMIN do escritório pode gerir a equipe.');
        }

        return $tenant;
    }

    private function assertMembershipInTenant(TenantMembership $membership, Tenant $tenant): void
    {
        if ((int) $membership->tenant_id !== (int) $tenant->id) {
            throw ActivationException::notFound('Membro não encontrado neste escritório.');
        }
    }

    private function userHasOtherActiveGrant(User $user, int $exceptMembershipId): bool
    {
        $otherMembership = TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $exceptMembershipId)
            ->where('is_active', true)
            ->exists();

        if ($otherMembership) {
            return true;
        }

        return PlatformMembership::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    private function revokeUserSessions(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizeMembership(TenantMembership $membership, ?AccountActivation $activation = null): array
    {
        $membership->loadMissing(['user', 'permissionProfile']);

        if ($activation === null) {
            $activation = AccountActivation::query()
                ->where('tenant_membership_id', $membership->id)
                ->orderByDesc('generation')
                ->orderByDesc('id')
                ->first();
        }

        $status = 'deactivated';
        if ($membership->is_active) {
            $status = 'active';
        } elseif ($activation !== null && $activation->isValid()) {
            $status = 'pending';
        } elseif ($activation !== null && $activation->isExpired() && ! $activation->isConsumed() && ! $activation->isRevoked()) {
            $status = 'expired';
        }

        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'name' => $membership->user?->name,
            'email' => $membership->user?->email,
            'role' => $membership->role->value,
            'permission_profile' => $membership->permissionProfile === null ? null : [
                'id' => $membership->permissionProfile->id,
                'key' => $membership->permissionProfile->key,
                'name' => $membership->permissionProfile->name,
            ],
            'is_active' => $membership->is_active,
            'status' => $status,
            'activation' => $activation?->toSanitizedArray(),
        ];
    }
}
