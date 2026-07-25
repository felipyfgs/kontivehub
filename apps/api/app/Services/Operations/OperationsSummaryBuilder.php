<?php

namespace App\Services\Operations;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\OutboxStatus;
use App\Enums\CredentialStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutationStatus;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\MeiAutomationStatus;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Enums\SyncCursorStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\CommunicationConversation;
use App\Models\CommunicationInbox;
use App\Models\CommunicationOutboxEntry;
use App\Models\Establishment;
use App\Models\Export;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMutationOperation;
use App\Models\FiscalPendingItem;
use App\Models\InstanceBackupRun;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\MeiAutomationAttempt;
use App\Models\NfseNote;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\OfficeSubscription;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use App\Models\TaxGuide;
use App\Models\TaxProxyPower;
use App\Services\Integra\TenantIntegraHealthService;
use App\Services\Outbound\SvrsNfceCircuitBreaker;
use App\Services\Outbound\SvrsNfceConfig;
use App\Services\Outbound\SvrsNfceKillSwitchService;
use App\Services\Usage\OfficeUsageQueryService;
use App\Support\FeatureFlags;

/**
 * Resumo operacional do escritório ativo — agregados sanitizados.
 * NÃO expõe contrato global SERPRO, credenciais, custo de outros tenants ou incidentes alheios.
 */
final class OperationsSummaryBuilder
{
    public function __construct(
        private readonly OperationsInboxBuilder $inbox,
        private readonly TenantIntegraHealthService $integraHealth,
        private readonly OfficeUsageQueryService $usage,
        private readonly SvrsNfceConfig $svrsConfig,
        private readonly SvrsNfceKillSwitchService $svrsKill,
        private readonly SvrsNfceCircuitBreaker $svrsBreaker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $officeId): array
    {
        $counts = $this->inbox->counts($officeId);
        $backup = InstanceBackupRun::statusSummary();
        $env = SerproEnvironment::tryFrom((string) config('serpro.default_environment', 'TRIAL'))
            ?? SerproEnvironment::Trial;

        $svrsBacklog = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Eligible,
                SvrsNfceRecoveryStatus::Queued,
                SvrsNfceRecoveryStatus::Running,
                SvrsNfceRecoveryStatus::RetryScheduled,
            ])
            ->count();

        return [
            'clients' => Client::query()->where('office_id', $officeId)->count(),
            'establishments' => Establishment::query()->where('office_id', $officeId)->count(),
            'notes' => NfseNote::query()->where('office_id', $officeId)->count(),
            'exports_ready' => Export::query()->where('office_id', $officeId)->where('status', 'READY')->count(),
            'exports_pending' => Export::query()
                ->where('office_id', $officeId)
                ->whereIn('status', ['PENDING', 'PROCESSING'])
                ->count(),
            'sync_due' => SyncCursor::query()
                ->where('office_id', $officeId)
                ->whereNotIn('status', [SyncCursorStatus::Blocked, SyncCursorStatus::Running])
                ->where('next_sync_at', '<=', now())
                ->count(),
            'sync_blocked' => SyncCursor::query()
                ->where('office_id', $officeId)
                ->where('status', SyncCursorStatus::Blocked)
                ->count(),
            'sync_failures_24h' => SyncRun::query()
                ->where('office_id', $officeId)
                ->where('status', 'FAILED')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'credentials_expiring_30d' => ClientCredential::query()
                ->where('office_id', $officeId)
                ->where('status', CredentialStatus::Active)
                ->where('valid_to', '<=', now()->addDays(30))
                ->count(),
            'inbox_critical' => $counts['inbox_critical'],
            'inbox_high' => $counts['inbox_high'],
            'inbox_total' => $counts['inbox_total'],
            'backup' => $backup,
            'svrs_nfce' => [
                'retrieval_enabled' => $this->svrsConfig->retrievalEnabled(),
                'auto_queue_enabled' => $this->svrsConfig->autoQueueEnabled(),
                'kill_switch' => $this->svrsKill->isActive(),
                'breaker_global' => $this->svrsBreaker->globalStatus()['state'],
                'backlog' => $svrsBacklog,
            ],
            'serpro_authorization' => $this->authorizationSummary($officeId, $env),
            'proxy_powers' => $this->proxyPowersSummary($officeId),
            'modules' => $this->modulesSummary($officeId),
            'fiscal_pending' => $this->fiscalPendingSummary($officeId),
            'fiscal_coverage' => $this->fiscalCoverageSummary($officeId),
            'usage' => $this->usageSummary($officeId),
            'subscription' => $this->subscriptionSummary($officeId),
            'blocks' => $this->blocksSummary($officeId, $env),
            'uncertain_results' => $this->uncertainResultsSummary($officeId),
            'platform_health' => $this->tenantScopedHealth($env),
            'guides_due_7d' => TaxGuide::query()
                ->where('office_id', $officeId)
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()->addDays(7))
                ->where('due_at', '>=', now()->subDay())
                ->whereNotIn('payment_status', [
                    TaxGuidePaymentStatus::Confirmed->value,
                ])
                ->count(),
            'communication' => $this->communicationSummary($officeId),
            'mei_automation' => $this->meiAutomationSummary($officeId),
            'fiscal_runs' => $this->fiscalRunsSummary($officeId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Rollup de Atendimento — só contagens do office; sem /healthz do gateway.
     *
     * @return array<string, mixed>
     */
    private function communicationSummary(int $officeId): array
    {
        try {
            $office = Office::query()->find($officeId);
            $byStatus = [];
            foreach (InboxStatus::cases() as $status) {
                $byStatus[$status->value] = CommunicationInbox::query()
                    ->where('office_id', $officeId)
                    ->where('status', $status->value)
                    ->count();
            }

            return [
                'available' => true,
                'global_enabled' => (bool) config('communication.enabled'),
                'gateway_enabled' => (bool) config('communication.gateway.enabled'),
                'office_enabled' => (bool) ($office?->communication_enabled ?? false),
                'inboxes_by_status' => $byStatus,
                'outbox_retry' => CommunicationOutboxEntry::query()
                    ->where('office_id', $officeId)
                    ->where('status', OutboxStatus::Retry->value)
                    ->count(),
                'outbox_dead' => CommunicationOutboxEntry::query()
                    ->where('office_id', $officeId)
                    ->where('status', OutboxStatus::Dead->value)
                    ->count(),
                'conversations_open' => CommunicationConversation::query()
                    ->where('office_id', $officeId)
                    ->where('status', ConversationStatus::Open->value)
                    ->count(),
                'conversations_pending' => CommunicationConversation::query()
                    ->where('office_id', $officeId)
                    ->where('status', ConversationStatus::Pending->value)
                    ->count(),
                'deep_link' => '/communication',
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'deep_link' => '/communication',
            ];
        }
    }

    /**
     * Contagens leves MEI 24h — fail-closed se a leitura falhar.
     *
     * @return array<string, mixed>
     */
    private function meiAutomationSummary(int $officeId): array
    {
        try {
            $since = now()->subDay();
            $base = MeiAutomationAttempt::query()
                ->where('office_id', $officeId)
                ->where('created_at', '>=', $since);

            return [
                'available' => true,
                'failed_24h' => (clone $base)->where('status', MeiAutomationStatus::Failed->value)->count(),
                'uncertain_24h' => (clone $base)->whereIn('status', [
                    MeiAutomationStatus::Uncertain->value,
                    MeiAutomationStatus::SyncLost->value,
                ])->count(),
                'running' => MeiAutomationAttempt::query()
                    ->where('office_id', $officeId)
                    ->whereIn('status', [
                        MeiAutomationStatus::Queued->value,
                        MeiAutomationStatus::Running->value,
                        MeiAutomationStatus::WaitingUserAction->value,
                    ])
                    ->count(),
                'deep_link' => '/monitoring/mei',
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'deep_link' => '/monitoring/mei',
            ];
        }
    }

    /**
     * Contagens leves de runs fiscais 24h.
     *
     * @return array<string, mixed>
     */
    private function fiscalRunsSummary(int $officeId): array
    {
        try {
            $since = now()->subDay();

            return [
                'available' => true,
                'failed_24h' => FiscalMonitoringRun::query()
                    ->where('office_id', $officeId)
                    ->where('status', FiscalRunStatus::Failed->value)
                    ->where('created_at', '>=', $since)
                    ->count(),
                'running' => FiscalMonitoringRun::query()
                    ->where('office_id', $officeId)
                    ->whereIn('status', [
                        FiscalRunStatus::Queued->value,
                        FiscalRunStatus::Running->value,
                    ])
                    ->count(),
                'deep_link' => '/monitoring',
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'deep_link' => '/monitoring',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizationSummary(int $officeId, SerproEnvironment $env): array
    {
        $auth = OfficeSerproAuthorization::query()
            ->where('office_id', $officeId)
            ->where('environment', $env->value)
            ->first();

        if ($auth === null) {
            return [
                'configured' => false,
                'status' => null,
                'actions_required' => [
                    ['code' => 'CONFIGURE_AUTHOR', 'message' => 'Configure o Autor do Pedido e o Termo.'],
                ],
                'has_termo' => false,
                'has_procurador_token' => false,
                'next_action' => 'CONFIGURE_AUTHOR',
            ];
        }

        $public = $auth->toPublicArray();
        $actions = $public['actions_required'] ?? [];

        return [
            'configured' => true,
            'status' => $public['status'],
            'actions_required' => $actions,
            'has_termo' => (bool) ($public['has_termo'] ?? false),
            'has_procurador_token' => (bool) ($public['has_procurador_token'] ?? false),
            'termo_valid_to' => $public['termo_valid_to'] ?? null,
            'procurador_token_expires_at' => $public['procurador_token_expires_at'] ?? null,
            'next_action' => $actions[0]['code'] ?? null,
            // sem vault ids, XML, tokens, CNPJ completo
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proxyPowersSummary(int $officeId): array
    {
        $base = TaxProxyPower::query()->where('office_id', $officeId);

        return [
            'active' => (clone $base)->where('status', TaxProxyPowerStatus::Active)->count(),
            'expired' => (clone $base)->where('status', TaxProxyPowerStatus::Expired)->count(),
            'revoked' => (clone $base)->where('status', TaxProxyPowerStatus::Revoked)->count(),
            'insufficient' => (clone $base)->where('status', TaxProxyPowerStatus::Insufficient)->count(),
            'expiring_30d' => (clone $base)
                ->where('status', TaxProxyPowerStatus::Active)
                ->whereNotNull('valid_to')
                ->where('valid_to', '<=', now()->addDays(30))
                ->where('valid_to', '>', now())
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modulesSummary(int $officeId): array
    {
        $modules = [];
        foreach (FeatureFlags::MODULES as $module) {
            $modules[$module] = [
                'enabled' => FeatureFlags::isModuleEnabled($module, $officeId),
                'mutating_enabled' => FeatureFlags::isMutatingEnabled($module, $officeId),
            ];
        }

        return [
            'kill_switch' => FeatureFlags::isKillSwitchActive(),
            'global_enabled' => FeatureFlags::isGloballyEnabled(),
            'modules' => $modules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fiscalPendingSummary(int $officeId): array
    {
        $open = FiscalPendingItem::query()
            ->where('office_id', $officeId)
            ->where('status', FiscalPendingStatus::Open);

        return [
            'open_total' => (clone $open)->count(),
            'open_critical' => (clone $open)->where('severity', 'CRITICAL')->count(),
            'open_high' => (clone $open)->where('severity', 'HIGH')->count(),
            'due_7d' => (clone $open)
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()->addDays(7))
                ->count(),
        ];
    }

    /**
     * Situações e coberturas — NÃO soma UNKNOWN/UNSUPPORTED como “em dia”.
     *
     * @return array<string, mixed>
     */
    private function fiscalCoverageSummary(int $officeId): array
    {
        $bySituation = [];
        foreach (FiscalSituation::cases() as $sit) {
            $bySituation[$sit->value] = FiscalCompetence::query()
                ->where('office_id', $officeId)
                ->where('situation', $sit->value)
                ->count();
        }

        $byCoverage = [];
        foreach (FiscalCoverage::cases() as $cov) {
            $byCoverage[$cov->value] = FiscalCompetence::query()
                ->where('office_id', $officeId)
                ->where('coverage', $cov->value)
                ->count();
        }

        // “Em dia” honesto: só UP_TO_DATE com cobertura FULL
        $upToDateFull = FiscalCompetence::query()
            ->where('office_id', $officeId)
            ->where('situation', FiscalSituation::UpToDate->value)
            ->where('coverage', FiscalCoverage::Full->value)
            ->count();

        return [
            'by_situation' => $bySituation,
            'by_coverage' => $byCoverage,
            'up_to_date_full_only' => $upToDateFull,
            'note' => 'UNKNOWN e UNSUPPORTED não contam como regularidade.',
        ];
    }

    /**
     * Consumo/franquia do próprio tenant — sem orçamento global nem outros offices.
     *
     * @return array<string, mixed>
     */
    private function usageSummary(int $officeId): array
    {
        try {
            $raw = $this->usage->summary($officeId);
        } catch (\Throwable) {
            return [
                'available' => false,
            ];
        }

        $summary = is_array($raw['summary'] ?? null) ? $raw['summary'] : [];

        return [
            'available' => true,
            'period_year' => $summary['period_year'] ?? null,
            'period_month' => $summary['period_month'] ?? null,
            'used_quantity' => $summary['used_quantity'] ?? 0,
            'reserved_open_quantity' => $summary['reserved_open_quantity'] ?? 0,
            'franchise_quota' => $summary['franchise_quota'] ?? null,
            'remaining' => $summary['remaining'] ?? null,
            'franchise_ratio' => $summary['franchise_ratio'] ?? null,
            'alert_threshold_reached' => (bool) ($summary['alert_threshold_reached'] ?? false),
            // intencionalmente omitidos: estimated_cost_micros (custo interno),
            // global_budget, global_used, by_tenant
            'deep_link' => '/conta/consumo',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionSummary(int $officeId): ?array
    {
        $sub = OfficeSubscription::query()->where('office_id', $officeId)->first();
        if ($sub === null) {
            return null;
        }

        $public = $sub->toPublicArray();

        return [
            'plan' => $public['plan'],
            'status' => $public['status'],
            'limits' => $public['limits'],
            'allows_mutations' => $public['allows_mutations'],
            'allows_external_calls' => $public['allows_external_calls'],
        ];
    }

    /**
     * Bloqueios aplicáveis ao tenant (sem identidade de outros escritórios).
     *
     * @return array<string, mixed>
     */
    private function blocksSummary(int $officeId, SerproEnvironment $env): array
    {
        $health = $this->tenantScopedHealth($env);
        $auth = OfficeSerproAuthorization::query()
            ->where('office_id', $officeId)
            ->where('environment', $env->value)
            ->first();

        $authBlocked = $auth !== null && in_array(
            $auth->status,
            [
                SerproAuthorizationStatus::Blocked,
                SerproAuthorizationStatus::Expired,
                SerproAuthorizationStatus::Revoked,
                SerproAuthorizationStatus::ActionRequired,
            ],
            true,
        );

        $missingAuth = $auth === null
            || ! $auth->status->allowsExternalCalls();

        $reasons = [];
        if (! ($health['available'] ?? false)) {
            $reasons[] = 'PLATFORM_UNAVAILABLE';
        }
        if ($health['kill_switch'] ?? false) {
            $reasons[] = 'KILL_SWITCH';
        }
        if ($health['circuit_open'] ?? false) {
            $reasons[] = 'CIRCUIT_OPEN';
        }
        if ($missingAuth) {
            $reasons[] = 'TENANT_AUTH_INCOMPLETE';
        }
        if ($authBlocked) {
            $reasons[] = 'TENANT_AUTH_BLOCKED';
        }
        if (FeatureFlags::isKillSwitchActive()) {
            $reasons[] = 'FEATURE_KILL_SWITCH';
        }

        $usage = $this->usageSummary($officeId);
        if (($usage['alert_threshold_reached'] ?? false) === true) {
            $reasons[] = 'USAGE_ALERT';
        }

        return [
            'blocked' => $reasons !== [] && (
                in_array('PLATFORM_UNAVAILABLE', $reasons, true)
                || in_array('KILL_SWITCH', $reasons, true)
                || in_array('CIRCUIT_OPEN', $reasons, true)
                || in_array('TENANT_AUTH_INCOMPLETE', $reasons, true)
                || in_array('TENANT_AUTH_BLOCKED', $reasons, true)
                || in_array('FEATURE_KILL_SWITCH', $reasons, true)
            ),
            'reasons' => $reasons,
            'next_action' => $this->authorizationSummary($officeId, $env)['next_action']
                ?? ($reasons[0] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uncertainResultsSummary(int $officeId): array
    {
        $statuses = [
            FiscalMutationStatus::UnknownResult->value,
            FiscalMutationStatus::Reconciling->value,
            FiscalMutationStatus::Sent->value,
        ];

        $mutations = FiscalMutationOperation::query()
            ->where('office_id', $officeId)
            ->whereIn('status', $statuses)
            ->count();

        return [
            'mutations_uncertain' => $mutations,
            'note' => 'UNKNOWN_RESULT exige reconciliação — sem retry cego.',
        ];
    }

    /**
     * Saúde SERPRO sanitizada — sem contrato global, fingerprint, custo ou outros tenants.
     *
     * @return array<string, mixed>
     */
    private function tenantScopedHealth(SerproEnvironment $env): array
    {
        $raw = $this->integraHealth->forEnvironment($env);

        // Garantir allowlist estrita (defesa em profundidade).
        return [
            'environment' => $raw['environment'] ?? $env->value,
            'available' => (bool) ($raw['available'] ?? false),
            'status' => $raw['status'] ?? 'UNAVAILABLE',
            'kill_switch' => (bool) ($raw['kill_switch'] ?? false),
            'circuit_open' => (bool) ($raw['circuit_open'] ?? false),
            'cert_expiring_soon' => (bool) ($raw['cert_expiring_soon'] ?? false),
            // Campos proibidos explicitamente omitidos:
            // contractor_cnpj*, fingerprint*, consumer_key*, has_pfx, has_oauth,
            // contracts[], active_contract e smoke_status.
            // global_budget, other tenants
        ];
    }
}
