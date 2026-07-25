<?php

namespace App\Services\Activation;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Models\AccountActivation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Revoga gerações válidas e emite nova ativação (mesmo e-mail).
 */
final class RegenerateActivationService
{
    public function __construct(
        private readonly ActivationCredentialService $credentials,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{activation: array<string, mixed>, credential_delivery: string, activation_url?: string, temporary_password?: string, method: string, expires_at: string}
     */
    public function regenerate(
        AccountActivation $current,
        ActivationMethod $method,
        User $actor,
    ): array {
        $issued = $this->credentials->issueSecret($method);
        $expiresAt = $this->credentials->expiresAtFor();

        $activation = DB::transaction(function () use ($current, $method, $issued, $expiresAt, $actor): AccountActivation {
            /** @var AccountActivation $locked */
            $locked = AccountActivation::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();

            if ($locked->isConsumed()) {
                throw ActivationException::invalid('Ativação já consumida; regeneração indisponível.');
            }

            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            // Revoga todas as gerações ainda válidas do mesmo propósito/usuário (e membership quando houver).
            $q = AccountActivation::query()
                ->where('user_id', $user->id)
                ->where('purpose', $locked->purpose)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at');

            if ($locked->office_membership_id !== null) {
                $q->where('office_membership_id', $locked->office_membership_id);
            }
            if ($locked->platform_membership_id !== null) {
                $q->where('platform_membership_id', $locked->platform_membership_id);
            }

            $q->lockForUpdate()->get()->each(function (AccountActivation $row): void {
                $row->forceFill(['revoked_at' => now()])->save();
            });

            $nextGeneration = (int) AccountActivation::query()
                ->where('user_id', $user->id)
                ->where('purpose', $locked->purpose)
                ->max('generation') + 1;

            $activation = AccountActivation::query()->create([
                'purpose' => $locked->purpose,
                'method' => $method,
                'user_id' => $user->id,
                'office_id' => $locked->office_id,
                'office_membership_id' => $locked->office_membership_id,
                'platform_membership_id' => $locked->platform_membership_id,
                'email_normalized' => $locked->email_normalized,
                'secret_hash' => $issued['hash'],
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'revoked_at' => null,
                'generation' => max(1, $nextGeneration),
                'created_by_user_id' => $actor->id,
            ]);

            // Usuário permanece pendente (sentinela + password_change_required).
            if (! $user->password_change_required) {
                $user->forceFill(['password_change_required' => true])->save();
            }
            if ($user->is_active && $locked->purpose !== ActivationPurpose::OfficeMember) {
                // Se ainda não concluiu e reativamos flag, não força inativo em legado multi-grant.
            }

            $this->audit->record(
                action: 'activation.regenerated',
                result: 'SUCCESS',
                subject: $activation,
                context: [
                    'purpose' => $activation->purpose->value,
                    'method' => $method->value,
                    'generation' => $activation->generation,
                    'email_masked' => AccountActivation::maskEmail($activation->email_normalized),
                    'previous_activation_id' => $locked->id,
                ],
                userId: $actor->id,
                officeId: $activation->office_id,
            );

            return $activation;
        });

        $payload = [
            'activation' => $activation->toSanitizedArray(),
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
}
