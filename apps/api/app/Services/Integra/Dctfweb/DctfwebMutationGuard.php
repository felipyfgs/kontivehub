<?php

namespace App\Services\Integra\Dctfweb;

use App\Enums\DctfwebMutationStatus;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\DctfwebMutationAttempt;
use App\Models\Office;
use App\Models\User;
use App\Services\Fiscal\Mutations\RecentTwoFactorGate;
use App\Support\FeatureFlags;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gates de mutação DCTFWeb/MIT (9.8):
 * flags mutantes OFF, ADMIN+2FA recente (fail-closed sem actor),
 * idempotência e bloqueio pós-timeout incerto.
 */
final class DctfwebMutationGuard
{
    public function __construct(
        private readonly RecentTwoFactorGate $totp,
    ) {}

    public function mutationsEnabled(int $officeId): bool
    {
        if (! (bool) config('fiscal_monitoring.mutating_enabled', false)) {
            return false;
        }

        return FeatureFlags::isMutatingEnabled(DctfwebCodes::MODULE, $officeId);
    }

    /**
     * @return array{allowed:bool,code:?string,message:?string}
     */
    public function assertMayMutate(
        Office $office,
        Client $client,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        ?string $periodKey = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): array {
        if (! $this->mutationsEnabled((int) $office->id)) {
            return [
                'allowed' => false,
                'code' => 'MUTATING_DISABLED',
                'message' => 'Transmissão/encerramento desabilitados (flags mutantes OFF).',
            ];
        }

        if ((int) $client->office_id !== (int) $office->id) {
            return [
                'allowed' => false,
                'code' => 'CONTRIBUTOR_CROSS_TENANT',
                'message' => 'Contribuinte de outro tenant.',
            ];
        }

        // Fail-closed: mutações exigem actor resolvido (ADMIN + 2FA recente).
        if ($actor === null) {
            return [
                'allowed' => false,
                'code' => 'ACTOR_REQUIRED',
                'message' => 'Mutação fiscal exige ator autenticado (ADMIN + 2FA).',
            ];
        }

        $role = $actor->roleIn($office);
        if ($role !== OfficeRole::Admin) {
            return [
                'allowed' => false,
                'code' => 'ADMIN_REQUIRED',
                'message' => 'Somente ADMIN pode executar mutações DCTFWeb/MIT.',
            ];
        }

        if (! $this->totp->isRecentlyConfirmed($actor)) {
            return [
                'allowed' => false,
                'code' => 'PASSWORD_CONFIRMATION_REQUIRED',
                'message' => 'Reconfirmação de senha recente obrigatória para mutação fiscal.',
            ];
        }

        $block = $this->findActiveUncertainBlock(
            (int) $office->id,
            (int) $client->id,
            $systemCode,
            $serviceCode,
            $operationCode,
            $periodKey,
        );
        if ($block !== null) {
            return [
                'allowed' => false,
                'code' => 'UNCERTAIN_RETRY_BLOCKED',
                'message' => 'Timeout incerto: reconciliação obrigatória antes de nova tentativa.',
            ];
        }

        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    public function findActiveUncertainBlock(
        int $officeId,
        int $clientId,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        ?string $periodKey = null,
    ): ?DctfwebMutationAttempt {
        $leaseMinutes = max(1, (int) config('fiscal_monitoring.mutation_inflight_lease_minutes', 30));
        $inflightSince = CarbonImmutable::now()->subMinutes($leaseMinutes);

        $q = DctfwebMutationAttempt::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('system_code', strtoupper($systemCode))
            ->where('service_code', strtoupper($serviceCode))
            ->where('operation_code', strtoupper($operationCode))
            ->where(function ($outer) use ($inflightSince): void {
                // UNCERTAIN: bloqueio até blocked_retry_until (ou indefinido se null)
                $outer->where(function ($u): void {
                    $u->where('status', DctfwebMutationStatus::Uncertain->value)
                        ->where(function ($inner): void {
                            $inner->whereNull('blocked_retry_until')
                                ->orWhere('blocked_retry_until', '>', CarbonImmutable::now());
                        });
                })->orWhere(function ($s) use ($inflightSince): void {
                    // SENT em voo: só durante lease (não trava retry eterno após crash)
                    $s->where('status', DctfwebMutationStatus::Sent->value)
                        ->where('sent_at', '>', $inflightSince);
                });
            })
            ->orderByDesc('id');

        if ($periodKey !== null) {
            $q->where('period_key', $periodKey);
        }

        return $q->first();
    }

    public function beginAttempt(
        Office $office,
        Client $client,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        string $idempotencyKey,
        ?string $periodKey = null,
        ?string $correlationId = null,
        ?int $competenceId = null,
    ): DctfwebMutationAttempt {
        $existing = DctfwebMutationAttempt::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DctfwebMutationAttempt::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'competence_id' => $competenceId,
            'system_code' => strtoupper($systemCode),
            'service_code' => strtoupper($serviceCode),
            'operation_code' => strtoupper($operationCode),
            'period_key' => $periodKey,
            'idempotency_key' => $idempotencyKey,
            'status' => DctfwebMutationStatus::Pending,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * Claim atômico PENDING → SENT antes do upstream (sem blocked_retry_until permanente).
     * Retorna a attempt claimada ou null se outro worker já claimou / estado terminal.
     * Em falha de transporte use markFailed (retry permitido); em timeout markUncertain.
     */
    public function claimForUpstream(DctfwebMutationAttempt $attempt): ?DctfwebMutationAttempt
    {
        return DB::transaction(function () use ($attempt) {
            /** @var DctfwebMutationAttempt|null $locked */
            $locked = DctfwebMutationAttempt::query()
                ->withoutGlobalScopes()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            if ($locked->status !== DctfwebMutationStatus::Pending) {
                return null;
            }

            $locked->forceFill([
                'status' => DctfwebMutationStatus::Sent,
                'sent_at' => CarbonImmutable::now(),
                // Sem blocked_retry_until: falha definitiva → Failed (retry OK);
                // timeout → Uncertain com bloqueio; crash → lease em findActiveUncertainBlock.
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * @deprecated Prefer claimForUpstream — markSent com bloqueio 24h antes do upstream trava retry.
     */
    public function markSent(DctfwebMutationAttempt $attempt): DctfwebMutationAttempt
    {
        $claimed = $this->claimForUpstream($attempt);
        if ($claimed !== null) {
            return $claimed;
        }

        return $attempt->fresh() ?? $attempt;
    }

    public function markUncertain(
        DctfwebMutationAttempt $attempt,
        string $errorCode = 'UNCERTAIN_TIMEOUT',
        ?string $message = null,
    ): DctfwebMutationAttempt {
        $attempt->forceFill([
            'status' => DctfwebMutationStatus::Uncertain,
            'error_code' => $errorCode,
            'error_message' => $message !== null ? mb_substr($message, 0, 500) : 'Resultado incerto após mutação.',
            'blocked_retry_until' => CarbonImmutable::now()->addHours(24),
            'sent_at' => $attempt->sent_at ?? CarbonImmutable::now(),
        ])->save();

        return $attempt->fresh();
    }

    public function markConfirmed(DctfwebMutationAttempt $attempt): DctfwebMutationAttempt
    {
        $attempt->forceFill([
            'status' => DctfwebMutationStatus::Confirmed,
            'resolved_at' => CarbonImmutable::now(),
            'blocked_retry_until' => null,
        ])->save();

        return $attempt->fresh();
    }

    public function markFailed(
        DctfwebMutationAttempt $attempt,
        string $errorCode,
        ?string $message = null,
    ): DctfwebMutationAttempt {
        $attempt->forceFill([
            'status' => DctfwebMutationStatus::Failed,
            'error_code' => $errorCode,
            'error_message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'resolved_at' => CarbonImmutable::now(),
            'blocked_retry_until' => null,
        ])->save();

        return $attempt->fresh();
    }

    public function markBlocked(
        DctfwebMutationAttempt $attempt,
        string $errorCode,
        ?string $message = null,
    ): DctfwebMutationAttempt {
        $attempt->forceFill([
            'status' => DctfwebMutationStatus::Blocked,
            'error_code' => $errorCode,
            'error_message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'resolved_at' => CarbonImmutable::now(),
        ])->save();

        return $attempt->fresh();
    }
}
