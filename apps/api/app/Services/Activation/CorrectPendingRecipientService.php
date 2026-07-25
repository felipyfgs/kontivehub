<?php

namespace App\Services\Activation;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\OfficeRole;
use App\Models\AccountActivation;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\PlatformMembership;
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
     * Corrige primeiro ADMIN de Office pendente.
     *
     * @return array<string, mixed>
     */
    public function correctOfficeFirstAdmin(
        Office $office,
        string $name,
        string $email,
        ActivationMethod $method,
        User $actor,
    ): array {
        if (! $office->isPendingActivation()) {
            throw ActivationException::forbidden('Correção disponível somente enquanto o Office está pendente.');
        }

        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();
        $newEmail = $this->credentials->normalizeEmail($email);
        $newName = trim($name);

        return DB::transaction(function () use ($office, $newName, $newEmail, $method, $issued, $expiresAt, $actor) {
            $office = Office::query()->whereKey($office->id)->lockForUpdate()->firstOrFail();

            if (! $office->isPendingActivation()) {
                throw ActivationException::forbidden('Correção disponível somente enquanto o Office está pendente.');
            }

            $membership = OfficeMembership::query()
                ->where('office_id', $office->id)
                ->where('role', OfficeRole::Admin)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw ActivationException::notFound('Primeiro administrador não encontrado.');
            }

            /** @var User $oldUser */
            $oldUser = User::query()->whereKey($membership->user_id)->lockForUpdate()->firstOrFail();

            $this->assertNeverActivated($oldUser, ActivationPurpose::OfficeFirstAdmin);

            if ($newEmail !== $this->credentials->normalizeEmail($oldUser->email)
                && User::query()->where('email', $newEmail)->exists()) {
                throw ActivationException::emailTaken();
            }

            $this->revokeAllForUserPurpose($oldUser->id, ActivationPurpose::OfficeFirstAdmin, $membership->id, null);

            $oldEmailMasked = AccountActivation::maskEmail($oldUser->email);
            $oldUserId = $oldUser->id;
            $oldMembershipId = $membership->id;

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

            $newMembership = OfficeMembership::query()->create([
                'office_id' => $office->id,
                'user_id' => $user->id,
                'role' => OfficeRole::Admin,
                'is_active' => false,
            ]);

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::OfficeFirstAdmin,
                'method' => $method,
                'user_id' => $user->id,
                'office_id' => $office->id,
                'office_membership_id' => $newMembership->id,
                'platform_membership_id' => null,
                'email_normalized' => $newEmail,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'office.first_admin_corrected',
                result: 'SUCCESS',
                subject: $office,
                context: [
                    'old_user_id' => $oldUserId,
                    'old_membership_id' => $oldMembershipId,
                    'old_email_masked' => $oldEmailMasked,
                    'new_user_id' => $user->id,
                    'new_email_masked' => AccountActivation::maskEmail($newEmail),
                    'method' => $method->value,
                ],
                userId: $actor->id,
                officeId: $office->id,
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
        ?int $defaultOfficeId = null,
    ): array {
        throw ActivationException::forbidden(
            'Correção de administrador global pendente foi descontinuada. '
            .'Use GET/PATCH /api/v1/platform/owner ou o comando app:platform-owner:recover.',
        );
    }

    /**
     * Corrige membro de equipe pendente (Office ADMIN real).
     *
     * @return array<string, mixed>
     */
    public function correctOfficeMember(
        OfficeMembership $membership,
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
            /** @var OfficeMembership $locked */
            $locked = OfficeMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active) {
                throw ActivationException::forbidden('Correção disponível somente enquanto o membro está pendente.');
            }

            /** @var User $oldUser */
            $oldUser = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $this->assertNeverActivated($oldUser, ActivationPurpose::OfficeMember);

            if ($newEmail !== $this->credentials->normalizeEmail($oldUser->email)
                && User::query()->where('email', $newEmail)->exists()) {
                throw ActivationException::emailTaken();
            }

            $role = $locked->role;
            $officeId = $locked->office_id;

            $this->revokeAllForUserPurpose($oldUser->id, ActivationPurpose::OfficeMember, $locked->id, null);

            $oldEmailMasked = AccountActivation::maskEmail($oldUser->email);
            $oldUserId = $oldUser->id;
            $oldMembershipId = $locked->id;

            $locked->delete();
            $this->deleteExclusiveNeverActivatedUser($oldUser);

            $user = User::query()->create([
                'name' => $newName,
                'email' => $newEmail,
                'password' => $this->credentials->makeSentinelPasswordHash(),
                'is_active' => false,
                'password_change_required' => true,
            ]);

            $newMembership = OfficeMembership::query()->create([
                'office_id' => $officeId,
                'user_id' => $user->id,
                'role' => $role,
                'is_active' => false,
            ]);

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::OfficeMember,
                'method' => $method,
                'user_id' => $user->id,
                'office_id' => $officeId,
                'office_membership_id' => $newMembership->id,
                'platform_membership_id' => null,
                'email_normalized' => $newEmail,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'office.member_recipient_corrected',
                result: 'SUCCESS',
                subject: $newMembership,
                context: [
                    'old_user_id' => $oldUserId,
                    'old_membership_id' => $oldMembershipId,
                    'old_email_masked' => $oldEmailMasked,
                    'new_user_id' => $user->id,
                    'new_email_masked' => AccountActivation::maskEmail($newEmail),
                    'method' => $method->value,
                ],
                userId: $actor->id,
                officeId: $officeId,
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
        ?int $officeMembershipId,
        ?int $platformMembershipId,
    ): void {
        $q = AccountActivation::query()
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at');

        if ($officeMembershipId !== null) {
            $q->where('office_membership_id', $officeMembershipId);
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
        $hasOtherMembership = OfficeMembership::query()->where('user_id', $user->id)->exists();
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
