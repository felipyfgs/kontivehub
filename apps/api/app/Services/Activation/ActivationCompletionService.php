<?php

namespace App\Services\Activation;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\OfficeLifecycleStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AccountActivation;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OfficeSubscription;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Usage\SubscriptionPeriodService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Inspeção e conclusão de ativações (link manual e primeiro acesso).
 */
final class ActivationCompletionService
{
    public function __construct(
        private readonly ActivationCredentialService $credentials,
        private readonly SubscriptionPeriodService $periods,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Resposta neutra em caso inválido (anti-enumeração).
     *
     * @return array{valid: bool, email_masked?: string, invite_name?: string, purpose?: string, method?: string, expires_at?: string}
     */
    public function inspectLink(string $token): array
    {
        $activation = $this->findByLinkToken($token);

        if ($activation === null || ! $activation->isValid()) {
            return ['valid' => false];
        }

        $activation->loadMissing('user');

        return [
            'valid' => true,
            'email_masked' => AccountActivation::maskEmail($activation->email_normalized),
            'invite_name' => $activation->user?->name,
            'purpose' => $activation->purpose->value,
            'method' => $activation->method->value,
            'expires_at' => $activation->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{user: User, purpose: string}
     */
    public function completeLink(string $token, string $permanentPassword): array
    {
        $hash = $this->credentials->hashToken($token);

        return $this->completeWithLookup(
            fn () => AccountActivation::query()
                ->where('secret_hash', $hash)
                ->where('method', ActivationMethod::ManualLink)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first(),
            $permanentPassword,
            expectedMethod: ActivationMethod::ManualLink,
            plainSecret: $token,
        );
    }

    /**
     * @return array{user: User, purpose: string}
     */
    public function completeFirstAccess(string $email, string $temporaryPassword, string $permanentPassword): array
    {
        $normalized = $this->credentials->normalizeEmail($email);

        return $this->completeWithLookup(
            fn () => AccountActivation::query()
                ->where('email_normalized', $normalized)
                ->where('method', ActivationMethod::TemporaryPassword)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->orderByDesc('generation')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(),
            $permanentPassword,
            expectedMethod: ActivationMethod::TemporaryPassword,
            plainSecret: $temporaryPassword,
        );
    }

    /**
     * @param  callable(): (?AccountActivation)  $finder
     * @return array{user: User, purpose: string}
     */
    private function completeWithLookup(
        callable $finder,
        string $permanentPassword,
        ActivationMethod $expectedMethod,
        string $plainSecret,
    ): array {
        $userId = null;
        $purposeValue = null;

        DB::transaction(function () use ($finder, $permanentPassword, $expectedMethod, $plainSecret, &$userId, &$purposeValue): void {
            $activation = $finder();

            if ($activation === null || $activation->method !== $expectedMethod) {
                throw ActivationException::invalid();
            }

            if ($activation->isExpired()) {
                throw ActivationException::invalid('Ativação expirada.');
            }

            if ($activation->isRevoked() || $activation->isConsumed()) {
                throw ActivationException::invalid();
            }

            $ok = $expectedMethod === ActivationMethod::ManualLink
                ? $this->credentials->verifyToken($plainSecret, $activation->secret_hash)
                : $this->credentials->verifyPassword($plainSecret, $activation->secret_hash);

            if (! $ok) {
                throw ActivationException::invalid();
            }

            /** @var User $user */
            $user = User::query()->whereKey($activation->user_id)->lockForUpdate()->firstOrFail();

            if ($this->credentials->normalizeEmail($user->email) !== $activation->email_normalized) {
                throw ActivationException::invalid();
            }

            // Senha permanente substitui o sentinela; cast hashed do model re-hasheia se plain.
            $user->forceFill([
                'password' => Hash::make($permanentPassword),
                'password_change_required' => false,
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            match ($activation->purpose) {
                ActivationPurpose::OfficeFirstAdmin => $this->activateFirstAdmin($activation),
                ActivationPurpose::OfficeMember => $this->activateMember($activation),
                ActivationPurpose::PlatformAdmin => $this->activatePlatformAdmin($activation, $user),
            };

            $activation->forceFill([
                'consumed_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'activation.completed',
                result: 'SUCCESS',
                subject: $activation,
                context: [
                    'purpose' => $activation->purpose->value,
                    'method' => $activation->method->value,
                    'generation' => $activation->generation,
                    'email_masked' => AccountActivation::maskEmail($activation->email_normalized),
                ],
                userId: $user->id,
                officeId: $activation->office_id,
            );

            $userId = $user->id;
            $purposeValue = $activation->purpose->value;
        });

        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        // Login somente após commit da ativação (DB::transaction já commitou ao retornar).
        $this->establishSession($user);

        return [
            'user' => $user,
            'purpose' => (string) $purposeValue,
        ];
    }

    private function activateFirstAdmin(AccountActivation $activation): void
    {
        $office = Office::query()->whereKey($activation->office_id)->lockForUpdate()->firstOrFail();
        $subscription = OfficeSubscription::query()
            ->where('office_id', $office->id)
            ->lockForUpdate()
            ->firstOrFail();

        $membership = OfficeMembership::query()
            ->whereKey($activation->office_membership_id)
            ->lockForUpdate()
            ->firstOrFail();

        $now = now();
        [$periodStart, $periodEnd] = $this->periods->initialBounds($now->toImmutable());

        $office->forceFill([
            'is_active' => true,
            'lifecycle_status' => OfficeLifecycleStatus::Active,
        ])->save();

        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'starts_at' => $now,
            'current_period_starts_at' => $periodStart,
            'current_period_ends_at' => $periodEnd,
            'ends_at' => null,
        ])->save();

        $membership->forceFill(['is_active' => true])->save();
    }

    private function activateMember(AccountActivation $activation): void
    {
        $membership = OfficeMembership::query()
            ->whereKey($activation->office_membership_id)
            ->lockForUpdate()
            ->firstOrFail();

        $membership->forceFill(['is_active' => true])->save();
        // Período comercial do Office NÃO é alterado.
    }

    private function activatePlatformAdmin(AccountActivation $activation, User $user): void
    {
        $pm = PlatformMembership::query()
            ->whereKey($activation->platform_membership_id)
            ->lockForUpdate()
            ->firstOrFail();

        $pm->forceFill(['is_active' => true])->save();

        if ($pm->default_office_id !== null && $user->selected_office_id === null) {
            // Não força selected_office_id — contexto privilegiado usa default_office_id.
        }
    }

    private function findByLinkToken(string $token): ?AccountActivation
    {
        if ($token === '' || strlen($token) < 32) {
            return null;
        }

        $hash = $this->credentials->hashToken($token);

        return AccountActivation::query()
            ->where('secret_hash', $hash)
            ->where('method', ActivationMethod::ManualLink)
            ->first();
    }

    private function establishSession(User $user): void
    {
        try {
            Auth::guard('web')->login($user);
            if (request()?->hasSession()) {
                request()->session()->regenerate();
            }
        } catch (\Throwable) {
            // Sessão falhou: senha permanente já permite login comum.
        }
    }
}
