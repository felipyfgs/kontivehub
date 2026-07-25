<?php

namespace App\Services\Activation;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\OfficeRole;
use App\Models\AccountActivation;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OfficeSubscription;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use Illuminate\Support\Facades\DB;

/**
 * Gestão de equipe do Office — OfficeMembership ADMIN real no CurrentOffice,
 * ou PLATFORM_ADMIN em contexto privilegiado com office resolvido.
 */
final class OfficeTeamService
{
    public function __construct(
        private readonly ActivationCredentialService $credentials,
        private readonly RegenerateActivationService $regenerate,
        private readonly CorrectPendingRecipientService $correctRecipient,
        private readonly CurrentOffice $currentOffice,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor): array
    {
        $office = $this->assertRealAdmin($actor);

        return OfficeMembership::query()
            ->with('user')
            ->where('office_id', $office->id)
            ->orderBy('id')
            ->get()
            ->map(fn (OfficeMembership $m) => $this->sanitizeMembership($m))
            ->all();
    }

    /**
     * @param  array{
     *   name: string,
     *   email: string,
     *   role: OfficeRole|string,
     *   method: ActivationMethod|string
     * }  $input
     * @return array<string, mixed>
     */
    public function createMember(User $actor, array $input): array
    {
        $office = $this->assertRealAdmin($actor);

        $role = $input['role'] instanceof OfficeRole
            ? $input['role']
            : OfficeRole::from((string) $input['role']);

        $method = $input['method'] instanceof ActivationMethod
            ? $input['method']
            : ActivationMethod::from((string) $input['method']);

        $name = trim((string) $input['name']);
        $email = $this->credentials->normalizeEmail((string) $input['email']);

        if (User::query()->where('email', $email)->exists()) {
            throw ActivationException::emailTaken();
        }

        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();

        $result = DB::transaction(function () use ($office, $name, $email, $role, $method, $issued, $expiresAt, $actor) {
            $office = Office::query()->whereKey($office->id)->lockForUpdate()->firstOrFail();
            $subscription = OfficeSubscription::query()
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                throw ActivationException::invalid('Assinatura do escritório não encontrada.');
            }

            $this->assertSeatAvailable($office, $subscription);

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

            $membership = OfficeMembership::query()->create([
                'office_id' => $office->id,
                'user_id' => $user->id,
                'role' => $role,
                'is_active' => false,
            ]);

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::OfficeMember,
                'method' => $method,
                'user_id' => $user->id,
                'office_id' => $office->id,
                'office_membership_id' => $membership->id,
                'platform_membership_id' => null,
                'email_normalized' => $email,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => 1,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'office.member_created',
                result: 'SUCCESS',
                subject: $membership,
                context: [
                    'role' => $role->value,
                    'method' => $method->value,
                    'email_masked' => AccountActivation::maskEmail($email),
                ],
                userId: $actor->id,
                officeId: $office->id,
            );

            return [$membership->load('user'), $activation];
        });

        /** @var OfficeMembership $membership */
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
    public function changeRole(User $actor, OfficeMembership $membership, OfficeRole $role): array
    {
        $office = $this->assertRealAdmin($actor);
        $this->assertMembershipInOffice($membership, $office);

        return DB::transaction(function () use ($membership, $role, $office, $actor) {
            /** @var OfficeMembership $locked */
            $locked = OfficeMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->role === OfficeRole::Admin && $role !== OfficeRole::Admin && $locked->is_active) {
                $this->assertNotLastActiveAdmin($office, $locked->id);
            }

            $from = $locked->role->value;
            $locked->forceFill(['role' => $role])->save();

            $this->audit->record(
                action: 'office.member_role_changed',
                result: 'SUCCESS',
                subject: $locked,
                context: [
                    'from_role' => $from,
                    'to_role' => $role->value,
                ],
                userId: $actor->id,
                officeId: $office->id,
            );

            return $this->sanitizeMembership($locked->load('user'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(User $actor, OfficeMembership $membership): array
    {
        $office = $this->assertRealAdmin($actor);
        $this->assertMembershipInOffice($membership, $office);

        return DB::transaction(function () use ($membership, $office, $actor) {
            /** @var OfficeMembership $locked */
            $locked = OfficeMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->role === OfficeRole::Admin && $locked->is_active) {
                $this->assertNotLastActiveAdmin($office, $locked->id);
            }

            $locked->forceFill(['is_active' => false])->save();

            AccountActivation::query()
                ->where('office_membership_id', $locked->id)
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
                action: 'office.member_deactivated',
                result: 'SUCCESS',
                subject: $locked,
                context: [
                    'user_id' => $user->id,
                    'user_global_deactivated' => ! $user->is_active,
                ],
                userId: $actor->id,
                officeId: $office->id,
            );

            return $this->sanitizeMembership($locked->load('user'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivate(
        User $actor,
        OfficeMembership $membership,
        ?ActivationMethod $method = null,
    ): array {
        $office = $this->assertRealAdmin($actor);
        $this->assertMembershipInOffice($membership, $office);

        $method ??= ActivationMethod::ManualLink;

        return DB::transaction(function () use ($membership, $office, $actor, $method) {
            /** @var OfficeMembership $locked */
            $locked = OfficeMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active) {
                throw ActivationException::conflict('Membership já está ativa.', 'already_active');
            }

            $subscription = OfficeSubscription::query()
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Contagem: reativação ocupará vaga se ainda não conta (desativada).
            $this->assertSeatAvailable($office, $subscription);

            /** @var User $user */
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            $hasOtherActive = $this->userHasOtherActiveGrant($user, $locked->id);

            if ($hasOtherActive) {
                // Legado multi-office: reativa membership sem trocar senha global.
                $locked->forceFill(['is_active' => true])->save();
                if (! $user->is_active) {
                    $user->forceFill(['is_active' => true])->save();
                }

                $this->audit->record(
                    action: 'office.member_reactivated_immediate',
                    result: 'SUCCESS',
                    subject: $locked,
                    context: ['user_id' => $user->id],
                    userId: $actor->id,
                    officeId: $office->id,
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
                ->where('office_membership_id', $locked->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (AccountActivation $a) => $a->forceFill(['revoked_at' => now()])->save());

            $nextGeneration = (int) AccountActivation::query()
                ->where('office_membership_id', $locked->id)
                ->max('generation') + 1;

            $user->forceFill([
                'is_active' => false,
                'password_change_required' => true,
                'password' => $this->credentials->makeSentinelPasswordHash(),
            ])->save();

            $activation = AccountActivation::query()->create([
                'purpose' => ActivationPurpose::OfficeMember,
                'method' => $method,
                'user_id' => $user->id,
                'office_id' => $office->id,
                'office_membership_id' => $locked->id,
                'platform_membership_id' => null,
                'email_normalized' => $this->credentials->normalizeEmail($user->email),
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'generation' => max(1, $nextGeneration),
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                action: 'office.member_reactivated_pending',
                result: 'SUCCESS',
                subject: $locked,
                context: [
                    'user_id' => $user->id,
                    'method' => $method->value,
                    'generation' => $activation->generation,
                ],
                userId: $actor->id,
                officeId: $office->id,
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
        OfficeMembership $membership,
        ActivationMethod $method,
    ): array {
        $office = $this->assertRealAdmin($actor);
        $this->assertMembershipInOffice($membership, $office);

        $activation = AccountActivation::query()
            ->where('office_membership_id', $membership->id)
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
        OfficeMembership $membership,
        string $name,
        string $email,
        ActivationMethod $method,
    ): array {
        $office = $this->assertRealAdmin($actor);
        $this->assertMembershipInOffice($membership, $office);

        return $this->correctRecipient->correctOfficeMember($membership, $name, $email, $method, $actor);
    }

    /**
     * Contagem de vagas: ativas + pendentes (com ativação válida); desativadas não contam.
     */
    public function occupiedSeats(Office $office): int
    {
        $memberships = OfficeMembership::query()
            ->where('office_id', $office->id)
            ->get(['id', 'is_active']);

        $count = 0;
        foreach ($memberships as $m) {
            if ($m->is_active) {
                $count++;

                continue;
            }

            $pending = AccountActivation::query()
                ->where('office_membership_id', $m->id)
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

    private function assertSeatAvailable(Office $office, OfficeSubscription $subscription): void
    {
        $max = (int) ($subscription->max_users ?? 0);
        if ($max <= 0) {
            throw ActivationException::seatLimit('Limite de usuários não configurado.');
        }

        if ($this->occupiedSeats($office) >= $max) {
            throw ActivationException::seatLimit();
        }
    }

    private function assertNotLastActiveAdmin(Office $office, int $exceptMembershipId): void
    {
        $otherAdmins = OfficeMembership::query()
            ->where('office_id', $office->id)
            ->where('role', OfficeRole::Admin)
            ->where('is_active', true)
            ->where('id', '!=', $exceptMembershipId)
            ->count();

        if ($otherAdmins === 0) {
            throw ActivationException::forbidden('Não é possível rebaixar ou desativar o último ADMIN ativo.');
        }
    }

    private function assertRealAdmin(User $actor): Office
    {
        $office = $this->currentOffice->resolve($actor);
        if ($office === null) {
            throw ActivationException::forbidden('Contexto de escritório obrigatório.');
        }

        // Proprietário da instalação atuando no office selecionado (seletor global).
        if ($this->currentOffice->isPlatformPrivileged() && $actor->isPlatformAdmin()) {
            return $office;
        }

        if (! $this->currentOffice->hasRealMembership()) {
            throw ActivationException::forbidden('Gestão de equipe exige membership ADMIN real no escritório.');
        }

        $role = $this->currentOffice->realOfficeRole();
        if ($role !== OfficeRole::Admin) {
            throw ActivationException::forbidden('Somente ADMIN do escritório pode gerir a equipe.');
        }

        // Membership real ativa no office.
        $membership = OfficeMembership::query()
            ->where('office_id', $office->id)
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->where('role', OfficeRole::Admin)
            ->first();

        if ($membership === null) {
            throw ActivationException::forbidden('Somente ADMIN do escritório pode gerir a equipe.');
        }

        return $office;
    }

    private function assertMembershipInOffice(OfficeMembership $membership, Office $office): void
    {
        if ((int) $membership->office_id !== (int) $office->id) {
            throw ActivationException::notFound('Membro não encontrado neste escritório.');
        }
    }

    private function userHasOtherActiveGrant(User $user, int $exceptMembershipId): bool
    {
        $otherMembership = OfficeMembership::query()
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
    public function sanitizeMembership(OfficeMembership $membership, ?AccountActivation $activation = null): array
    {
        $membership->loadMissing('user');

        if ($activation === null) {
            $activation = AccountActivation::query()
                ->where('office_membership_id', $membership->id)
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
            'is_active' => $membership->is_active,
            'status' => $status,
            'activation' => $activation?->toSanitizedArray(),
        ];
    }
}
