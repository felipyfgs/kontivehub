<?php

namespace App\Services\Activation;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\TenantRole;
use App\Models\AccountActivation;
use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Corrige nome/e-mail de destinatário nunca ativado (não é regeneração).
 */
final class CorrectPendingRecipientService
{
    public function __construct(
        private readonly ActivationCredentialService $credentials,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Corrige primeiro ADMIN de Tenant pendente.
     *
     * @return array<string, mixed>
     */
    public function correctTenantFirstAdmin(
        Tenant $tenant,
        string $name,
        string $email,
        ActivationMethod $method,
        User $actor,
    ): array {
        if (! $tenant->isPendingActivation()) {
            throw ActivationException::forbidden('Correção disponível somente enquanto o Tenant está pendente.');
        }

        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();
        $newEmail = $this->credentials->normalizeEmail($email);
        $newName = trim($name);

        return DB::transaction(function () use ($tenant, $newName, $newEmail, $method, $issued, $expiresAt, $actor) {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if (! $tenant->isPendingActivation()) {
                throw ActivationException::forbidden('Correção disponível somente enquanto o Tenant está pendente.');
            }

            $membership = TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', TenantRole::TenantAdmin)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw ActivationException::notFound('Primeiro administrador não encontrado.');
            }

            /** @var User $oldUser */
            $oldUser = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();

            $this->assertNeverActivated($oldUser, ActivationPurpose::TenantFirstAdmin);

            if ($newEmail !== $this->credentials->normalizeEmail($oldUser->email)
                && User::query()->where('email', $newEmail)->exists()) {
                throw ActivationException::emailTaken();
            }

            $this->revokeAllForUserPurpose($oldUser->id, ActivationPurpose::TenantFirstAdmin, $membership->id, null);

            $previousEmailMasked = AccountActivation::maskEmail($oldUser->email);
            $previousUserId = $oldUser->id;
            $previousMembershipId = $membership->id;

            // Remove membership e usuário exclusivos nunca ativados.
            $membership->delete();
            $this->deleteExclusiveNeverActivatedUser($oldUser);

            $user = User::query()->create([
                'name' => $newName,
                'email' => $newEmail,
                'password' => $this->credentials->makeSentinelPasswordHash(),
                'is_active' => false,
                'password_change_required' => true,
            ]);

            $newMembership = TenantMembership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => TenantRole::TenantAdmin,
                'is_active' => false,
            ]);

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::TenantFirstAdmin,
                'method' => $method,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'tenant_membership_id' => $newMembership->id,
                'platform_membership_id' => null,
                'email_normalized' => $newEmail,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'tenant.first_admin_corrected',
                result: 'SUCCESS',
                subject: $tenant,
                context: [
                    'previous_user_id' => $previousUserId,
                    'previous_membership_id' => $previousMembershipId,
                    'previous_email_masked' => $previousEmailMasked,
                    'new_user_id' => $user->id,
                    'new_email_masked' => AccountActivation::maskEmail($newEmail),
                    'method' => $method->value,
                ],
                userId: $actor->id,
                tenantId: $tenant->id,
            );

            return $this->secretPayload($activation, $issued, $expiresAt->toIso8601String(), [
                'first_admin' => [
                    'membership_id' => $newMembership->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => false,
                ],
            ]);
        });
    }

    /**
     * Correção global pendente foi removida: use recuperação do Proprietário (host).
     *
     * @return never
     */
    public function correctPlatformAdmin(
        User $target,
        string $name,
        string $email,
        ActivationMethod $method,
        User $actor,
        ?int $defaultTenantId = null,
    ): array {
        throw ActivationException::forbidden(
            'Correção de administrador global pendente foi descontinuada. '
            .'Use GET/PATCH /api/v1/platform/owner ou o comando app:platform-owner:recover.',
        );
    }

    /**
     * Corrige membro de equipe pendente (Tenant ADMIN real).
     *
     * @return array<string, mixed>
     */
    public function correctTenantMember(
        TenantMembership $membership,
        string $name,
        string $email,
        ActivationMethod $method,
        User $actor,
    ): array {
        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();
        $newEmail = $this->credentials->normalizeEmail($email);
        $newName = trim($name);

        return DB::transaction(function () use ($membership, $newName, $newEmail, $method, $issued, $expiresAt, $actor) {
            /** @var TenantMembership $locked */
            $locked = TenantMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active) {
                throw ActivationException::forbidden('Correção disponível somente enquanto o membro está pendente.');
            }

            /** @var User $oldUser */
            $oldUser = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $this->assertNeverActivated($oldUser, ActivationPurpose::TenantMember);

            if ($newEmail !== $this->credentials->normalizeEmail($oldUser->email)
                && User::query()->where('email', $newEmail)->exists()) {
                throw ActivationException::emailTaken();
            }

            $role = $locked->role;
            $tenantId = $locked->tenant_id;

            $this->revokeAllForUserPurpose($oldUser->id, ActivationPurpose::TenantMember, $locked->id, null);

            $previousEmailMasked = AccountActivation::maskEmail($oldUser->email);
            $previousUserId = $oldUser->id;
            $previousMembershipId = $locked->id;

            $locked->delete();
            $this->deleteExclusiveNeverActivatedUser($oldUser);

            $user = User::query()->create([
                'name' => $newName,
                'email' => $newEmail,
                'password' => $this->credentials->makeSentinelPasswordHash(),
                'is_active' => false,
                'password_change_required' => true,
            ]);

            $newMembership = TenantMembership::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role' => $role,
                'is_active' => false,
            ]);

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::TenantMember,
                'method' => $method,
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'tenant_membership_id' => $newMembership->id,
                'platform_membership_id' => null,
                'email_normalized' => $newEmail,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'tenant.member_recipient_corrected',
                result: 'SUCCESS',
                subject: $newMembership,
                context: [
                    'previous_user_id' => $previousUserId,
                    'previous_membership_id' => $previousMembershipId,
                    'previous_email_masked' => $previousEmailMasked,
                    'new_user_id' => $user->id,
                    'new_email_masked' => AccountActivation::maskEmail($newEmail),
                    'method' => $method->value,
                ],
                userId: $actor->id,
                tenantId: $tenantId,
            );

            return $this->secretPayload($activation, $issued, $expiresAt->toIso8601String(), [
                'membership' => [
                    'id' => $newMembership->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role->value,
                    'is_active' => false,
                ],
            ]);
        });
    }

    private function assertNeverActivated(User $user, ActivationPurpose $purpose): void
    {
        $consumed = AccountActivation::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNotNull('consumed_at')
            ->exists();

        if ($consumed || ($user->is_active && ! $user->password_change_required)) {
            throw ActivationException::forbidden('Destinatário já foi ativado; use gestão normal.');
        }
    }

    private function revokeAllForUserPurpose(
        int $userId,
        ActivationPurpose $purpose,
        ?int $tenantMembershipId,
        ?int $platformMembershipId,
    ): void {
        $q = AccountActivation::query()
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at');

        if ($tenantMembershipId !== null) {
            $q->where('tenant_membership_id', $tenantMembershipId);
        }
        if ($platformMembershipId !== null) {
            $q->where('platform_membership_id', $platformMembershipId);
        }

        $q->lockForUpdate()->get()->each(function (AccountActivation $row): void {
            $row->forceFill(['revoked_at' => now()])->save();
        });
    }

    private function deleteExclusiveNeverActivatedUser(User $user): void
    {
        $hasOtherMembership = TenantMembership::query()->where('user_id', $user->id)->exists();
        $hasPlatform = PlatformMembership::query()->where('user_id', $user->id)->exists();

        if ($hasOtherMembership || $hasPlatform) {
            // Não remove se ainda houver grants (não deveria no fluxo pendente exclusivo).
            return;
        }

        // Limpa ativações residualmente ligadas ao user.
        AccountActivation::query()->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->delete();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $issued
     * @return array<string, mixed>
     */
    private function secretPayload(AccountActivation $activation, array $issued, string $expiresAt, array $extra = []): array
    {
        $payload = array_merge($extra, [
            'activation' => $activation->toSanitizedArray(),
            'credential_delivery' => 'delivered',
            'method' => $activation->method->value,
            'expires_at' => $expiresAt,
        ]);

        if (isset($issued['activation_url'])) {
            $payload['activation_url'] = $issued['activation_url'];
        }
        if (isset($issued['temporary_password'])) {
            $payload['temporary_password'] = $issued['temporary_password'];
        }

        return $payload;
    }
}
