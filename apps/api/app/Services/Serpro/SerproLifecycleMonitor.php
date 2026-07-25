<?php

namespace App\Services\Serpro;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\TaxProxyPowerStatus;
use App\Enums\TermRePresentationStrategy;
use App\Jobs\Serpro\RenewOfficeProcuradorTokenJob;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\ClientProcuracaoSync;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproContract;
use App\Models\TaxProxyPower;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\OfficeSerproAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Verifica PFX contratante, A1, Termo, token e procurações.
 * Renova token do procurador somente quando a estratégia for REUSE_STORED_TERM.
 * NUNCA assina Termo, renova procuração ou muta fiscal além do token permitido.
 */
final class SerproLifecycleMonitor
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OfficeSerproAuthorizationService $authorizations,
    ) {}

    /**
     * @return array{
     *   alerts: list<array<string, mixed>>,
     *   scanned: array<string, int>,
     *   lock_acquired: bool
     * }
     */
    public function scan(): array
    {
        $lockSeconds = (int) config('serpro.lifecycle.lock_seconds', 120);
        $lock = Cache::lock('serpro.lifecycle.scan', max(30, $lockSeconds));

        if (! $lock->get()) {
            return [
                'alerts' => [],
                'scanned' => [],
                'lock_acquired' => false,
            ];
        }

        try {
            $alertDays = config('serpro.lifecycle.alert_days', [30, 7, 1]);
            if (! is_array($alertDays)) {
                $alertDays = [30, 7, 1];
            }
            $alertDays = array_values(array_unique(array_map('intval', $alertDays)));
            sort($alertDays); // menor janela aplicável primeiro (1,7,15,30,60,90)

            $alerts = [];
            $now = CarbonImmutable::now();
            $skew = (int) config('serpro.lifecycle.token_renewal_skew_seconds', 300);

            $contracts = 0;
            foreach (SerproContract::query()->orderBy('id')->get() as $contract) {
                $contracts++;
                if ($contract->cert_valid_to !== null) {
                    $alerts = array_merge($alerts, $this->windowAlerts(
                        kind: 'CONTRACTOR_PFX',
                        subjectId: (int) $contract->id,
                        officeId: null,
                        validTo: CarbonImmutable::parse((string) $contract->cert_valid_to),
                        now: $now,
                        alertDays: $alertDays,
                        meta: [
                            'environment' => $contract->environment->value ?? (string) $contract->environment,
                        ],
                    ));
                }
            }

            $auths = 0;
            foreach (OfficeSerproAuthorization::query()->orderBy('id')->get() as $auth) {
                $auths++;
                if ($auth->author_cert_valid_to !== null) {
                    $alerts = array_merge($alerts, $this->windowAlerts(
                        kind: 'AUTHOR_A1',
                        subjectId: (int) $auth->id,
                        officeId: (int) $auth->office_id,
                        validTo: CarbonImmutable::parse((string) $auth->author_cert_valid_to),
                        now: $now,
                        alertDays: $alertDays,
                        meta: ['environment' => $auth->environment->value ?? (string) $auth->environment],
                    ));
                }
                if ($auth->termo_valid_to !== null) {
                    $alerts = array_merge($alerts, $this->windowAlerts(
                        kind: 'TERMO',
                        subjectId: (int) $auth->id,
                        officeId: (int) $auth->office_id,
                        validTo: CarbonImmutable::parse((string) $auth->termo_valid_to),
                        now: $now,
                        alertDays: $alertDays,
                        meta: ['environment' => $auth->environment->value ?? (string) $auth->environment],
                    ));
                }
                if ($auth->procurador_token_expires_at !== null) {
                    $exp = CarbonImmutable::parse((string) $auth->procurador_token_expires_at);
                    $secondsLeft = $now->diffInSeconds($exp, false);
                    if ($secondsLeft <= $skew) {
                        $strategy = $this->authorizations->representationStrategy($auth->environment);
                        $canAutoRenew = $strategy === TermRePresentationStrategy::ReuseStoredTerm;

                        $alerts[] = [
                            'kind' => 'PROCURADOR_TOKEN',
                            'subject_id' => (int) $auth->id,
                            'office_id' => (int) $auth->office_id,
                            'days_left' => 0,
                            'severity' => $secondsLeft <= 0
                                ? ($canAutoRenew ? 'AUTO_RENEW' : 'EXPIRED')
                                : ($canAutoRenew ? 'AUTO_RENEW_SKEW' : 'RENEWAL_SKEW'),
                            'message' => $canAutoRenew
                                ? ($secondsLeft <= 0
                                    ? 'Token do procurador expirado — renovação automática enfileirada.'
                                    : 'Token do procurador na margem de renovação — renovação automática enfileirada.')
                                : ($secondsLeft <= 0
                                    ? 'Token do procurador expirado — renovação manual/consentida necessária.'
                                    : 'Token do procurador na margem de renovação (skew) — jobs que ultrapassariam a validade devem bloquear.'),
                            'expires_at' => $exp->toIso8601String(),
                        ];

                        if ($canAutoRenew) {
                            RenewOfficeProcuradorTokenJob::dispatch(
                                (int) $auth->office_id,
                                $auth->environment->value ?? (string) $auth->environment,
                            );
                        } elseif ($secondsLeft <= 0 && $auth->status === SerproAuthorizationStatus::TokenActive) {
                            // Marca action required se expirado; NÃO renova automaticamente
                            $auth->status = SerproAuthorizationStatus::ActionRequired;
                            $auth->action_required_reason = 'Token do procurador expirado; renovação exige ação explícita.';
                            $auth->save();
                        }
                    }
                }
            }

            $powers = 0;
            foreach (TaxProxyPower::query()->whereNull('closed_at')->orderBy('id')->cursor() as $power) {
                $powers++;
                if ($power->valid_to !== null) {
                    if ($power->valid_to->lessThanOrEqualTo($now)
                        && $power->status === TaxProxyPowerStatus::Active) {
                        $power->status = TaxProxyPowerStatus::Expired;
                        $power->last_check_result = 'EXPIRED_LOCAL';
                        $power->save();
                    }
                    $alerts = array_merge($alerts, $this->windowAlerts(
                        kind: 'PROXY_POWER',
                        subjectId: (int) $power->id,
                        officeId: (int) $power->office_id,
                        validTo: CarbonImmutable::parse((string) $power->valid_to),
                        now: $now,
                        alertDays: $alertDays,
                        meta: [
                            'power_code' => $power->power_code,
                            'client_id' => $power->client_id,
                        ],
                    ));
                }
            }

            $snapshotsExpired = 0;
            foreach (ClientProcuracaoSnapshot::query()
                ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                ->whereNotNull('valid_to')
                ->where('valid_to', '<=', $now)
                ->orderBy('id')
                ->cursor() as $snapshot) {
                $snapshot->status = ClientProcuracaoSyncStatus::Expired;
                $snapshot->last_check_result = 'EXPIRED_LOCAL';
                $snapshot->save();
                ClientProcuracaoSync::query()
                    ->where('office_id', $snapshot->office_id)
                    ->where('client_id', $snapshot->client_id)
                    ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                    ->update([
                        'status' => ClientProcuracaoSyncStatus::Expired->value,
                        'last_check_result' => 'EXPIRED_LOCAL',
                        'updated_at' => now(),
                    ]);
                $snapshotsExpired++;
            }

            foreach ($alerts as $alert) {
                Log::info('serpro.lifecycle.alert', [
                    'kind' => $alert['kind'],
                    'subject_id' => $alert['subject_id'],
                    'office_id' => $alert['office_id'],
                    'days_left' => $alert['days_left'],
                    'severity' => $alert['severity'],
                    // sem PII/segredos
                ]);
            }

            if ($alerts !== []) {
                $this->audit->record('serpro.lifecycle.scan', 'SUCCESS', null, [
                    'alerts_count' => count($alerts),
                    'kinds' => array_values(array_unique(array_column($alerts, 'kind'))),
                ], null, null);
            }

            return [
                'alerts' => $alerts,
                'scanned' => [
                    'contracts' => $contracts,
                    'authorizations' => $auths,
                    'proxy_powers' => $powers,
                    'procuracao_snapshots_expired' => $snapshotsExpired,
                ],
                'lock_acquired' => true,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $alertDays
     * @param  array<string, mixed>  $meta
     * @return list<array<string, mixed>>
     */
    private function windowAlerts(
        string $kind,
        int $subjectId,
        ?int $officeId,
        CarbonImmutable $validTo,
        CarbonImmutable $now,
        array $alertDays,
        array $meta = [],
    ): array {
        $daysLeft = (int) $now->startOfDay()->diffInDays($validTo->startOfDay(), false);

        if ($daysLeft < 0) {
            return [[
                'kind' => $kind,
                'subject_id' => $subjectId,
                'office_id' => $officeId,
                'days_left' => $daysLeft,
                'severity' => 'EXPIRED',
                'message' => "{$kind} expirado.",
                'expires_at' => $validTo->toIso8601String(),
                'meta' => $meta,
            ]];
        }

        // Escolhe a menor janela tal que daysLeft <= window (ex.: 5 dias → D7, não D90).
        $matched = null;
        foreach ($alertDays as $window) {
            if ($daysLeft <= $window) {
                $matched = $window;
                break;
            }
        }

        if ($matched === null) {
            return [];
        }

        return [[
            'kind' => $kind,
            'subject_id' => $subjectId,
            'office_id' => $officeId,
            'days_left' => $daysLeft,
            'severity' => 'D'.$matched,
            'message' => "{$kind} vence em {$daysLeft} dia(s) (janela {$matched}).",
            'expires_at' => $validTo->toIso8601String(),
            'meta' => $meta,
        ]];
    }
}
