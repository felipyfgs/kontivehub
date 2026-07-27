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
use App\Models\DocumentExport;
use App\Models\Establishment;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMutationOperation;
use App\Models\FiscalPendingItem;
use App\Models\InstanceBackupRun;
use App\Models\MeiAutomationAttempt;
use App\Models\NfseDocument;
use App\Models\OutboundRetrievalRequest;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use App\Models\TaxGuide;
use App\Models\TaxProxyPower;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Models\TenantSubscription;
use App\Services\Integra\TenantIntegraHealthService;
use App\Services\Outbound\SvrsNfceCircuitBreaker;
use App\Services\Outbound\SvrsNfceConfig;
use App\Services\Outbound\SvrsNfceKillSwitchService;
use App\Services\Usage\TenantUsageQueryService;
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
        private readonly TenantUsageQueryService $usage,
        private readonly SvrsNfceConfig $svrsConfig,
        private readonly SvrsNfceKillSwitchService $svrsKill,
        private readonly SvrsNfceCircuitBreaker $svrsBreaker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId): array
    {
        $counts = $this->inbox->counts($tenantId);
        $backup = InstanceBackupRun::statusSummary();
        $env = SerproEnvironment::tryFrom((string) config('serpro.default_environment', 'TRIAL'))
            ?? SerproEnvironment::Trial;

        $svrsBacklog = OutboundRetrievalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Eligible,
                SvrsNfceRecoveryStatus::Queued,
                SvrsNfceRecoveryStatus::Running,
                SvrsNfceRecoveryStatus::RetryScheduled,
            ])
            ->count();

        return [
            'clients' => Client::query()->where('tenant_id', $tenantId)->count(),
            'establishments' => Establishment::query()->where('tenant_id', $tenantId)->count(),
            'notes' => NfseDocument::query()->where('tenant_id', $tenantId)->count(),
            'exports_ready' => DocumentExport::query()->where('tenant_id', $tenantId)->where('status', 'READY')->count(),
            'exports_pending' => DocumentExport::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['PENDING', 'PROCESSING'])
                ->count(),
            'sync_due' => SyncCursor::query()
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', [SyncCursorStatus::Blocked, SyncCursorStatus::Running])
                ->where('next_sync_at', '<=', now())
                ->count(),
            'sync_blocked' => SyncCursor::query()
                ->where('tenant_id', $tenantId)
                ->where('status', SyncCursorStatus::Blocked)
                ->count(),
            'sync_failures_24h' => SyncRun::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'FAILED')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'credentials_expiring_30d' => ClientCredential::query()
                ->where('tenant_id', $tenantId)
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
            'serpro_authorization' => $this->authorizationSummary($tenantId, $env),
            'proxy_powers' => $this->proxyPowersSummary($tenantId),
            'modules' => $this->modulesSummary($tenantId),
            'fiscal_pending' => $this->fiscalPendingSummary($tenantId),
            'fiscal_coverage' => $this->fiscalCoverageSummary($tenantId),
            'usage' => $this->usageSummary($tenantId),
            'subscription' => $this->subscriptionSummary($tenantId),
            'blocks' => $this->blocksSummary($tenantId, $env),
            'uncertain_results' => $this->uncertainResultsSummary($tenantId),
            'platform_health' => $this->tenantScopedHealth($env),
            'guides_due_7d' => TaxGuide::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now()->addDays(7))
                ->where('due_at', '>=', now()->subDay())
                ->whereNotIn('payment_status', [
                    TaxGuidePaymentStatus::Confirmed->value,
                ])
                ->count(),
            'communication' => $this->communicationSummary($tenantId),
            'mei_automation' => $this->meiAutomationSummary($tenantId),
            'fiscal_runs' => $this->fiscalRunsSummary($tenantId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Rollup de Atendimento — só contagens do tenant; sem /healthz do gateway.
     *
     * @return array<string, mixed>
     */
    private function communicationSummary(int $tenantId): array
    {
        try {
            $tenant = Tenant::query()->find($tenantId);
            $byStatus = [];
            foreach (InboxStatus::cases() as $status) {
                $byStatus[$status->value] = CommunicationInbox::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', $status->value)
                    ->count();
            }

            return [
                'available' => true,
                'global_enabled' => (bool) config('communication.enabled'),
                'gateway_enabled' => (bool) config('communication.gateway.enabled'),
                'tenant_enabled' => (bool) ($tenant?->communication_enabled ?? false),
                'inboxes_by_status' => $byStatus,
                'outbox_retry' => CommunicationOutboxEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', OutboxStatus::Retry->value)
                    ->count(),
                'outbox_dead' => CommunicationOutboxEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', OutboxStatus::Dead->value)
                    ->count(),
                'conversations_open' => CommunicationConversation::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', ConversationStatus::Open->value)
                    ->count(),
                'conversations_pending' => CommunicationConversation::query()
                    ->where('tenant_id', $tenantId)
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
    private function meiAutomationSummary(int $tenantId): array
    {
        try {
            $since = now()->subDay();
            $base = MeiAutomationAttempt::query()
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $since);

            return [
                'available' => true,
                'failed_24h' => (clone $base)->where('status', MeiAutomationStatus::Failed->value)->count(),
                'uncertain_24h' => (clone $base)->whereIn('status', [
                    MeiAutomationStatus::Uncertain->value,
                    MeiAutomationStatus::SyncLost->value,
                ])->count(),
                'running' => MeiAutomationAttempt::query()
                    ->where('tenant_id', $tenantId)
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
    private function fiscalRunsSummary(int $tenantId): array
    {
        try {
            $since = now()->subDay();

            return [
                'available' => true,
                'failed_24h' => FiscalMonitoringRun::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', FiscalRunStatus::Failed->value)
                    ->where('created_at', '>=', $since)
                    ->count(),
                'running' => FiscalMonitoringRun::query()
                    ->where('tenant_id', $tenantId)
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
    private function authorizationSummary(int $tenantId, SerproEnvironment $env): array
    {
        $auth = TenantSerproAuthorization::query()
            ->where('tenant_id', $tenantId)
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
    private function proxyPowersSummary(int $tenantId): array
    {
        $base = TaxProxyPower::query()->where('tenant_id', $tenantId);

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
    private function modulesSummary(int $tenantId): array
    {
        $modules = [];
        foreach (FeatureFlags::MODULES as $module) {
            $modules[$module] = [
                'enabled' => FeatureFlags::isModuleEnabled($module, $tenantId),
                'mutating_enabled' => FeatureFlags::isMutatingEnabled($module, $tenantId),
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
    private function fiscalPendingSummary(int $tenantId): array
    {
        $open = FiscalPendingItem::query()
            ->where('tenant_id', $tenantId)
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
    private function fiscalCoverageSummary(int $tenantId): array
    {
        $bySituation = [];
        foreach (FiscalSituation::cases() as $sit) {
            $bySituation[$sit->value] = FiscalCompetence::query()
                ->where('tenant_id', $tenantId)
                ->where('situation', $sit->value)
                ->count();
        }

        $byCoverage = [];
        foreach (FiscalCoverage::cases() as $cov) {
            $byCoverage[$cov->value] = FiscalCompetence::query()
                ->where('tenant_id', $tenantId)
                ->where('coverage', $cov->value)
                ->count();
        }

        // “Em dia” honesto: só UP_TO_DATE com cobertura FULL
        $upToDateFull = FiscalCompetence::query()
            ->where('tenant_id', $tenantId)
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
     * Consumo/franquia do próprio tenant — sem orçamento global nem outros tenants.
     *
     * @return array<string, mixed>
     */
    private function usageSummary(int $tenantId): array
    {
        try {
            $raw = $this->usage->summary($tenantId);
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
            // O custo interno e agregados de outros tenants não fazem parte deste payload.
            'deep_link' => '/conta/consumo',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subscriptionSummary(int $tenantId): ?array
    {
        $sub = TenantSubscription::query()->where('tenant_id', $tenantId)->first();
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
    private function blocksSummary(int $tenantId, SerproEnvironment $env): array
    {
        $health = $this->tenantScopedHealth($env);
        $auth = TenantSerproAuthorization::query()
            ->where('tenant_id', $tenantId)
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

        $usage = $this->usageSummary($tenantId);
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
            'next_action' => $this->authorizationSummary($tenantId, $env)['next_action']
                ?? ($reasons[0] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uncertainResultsSummary(int $tenantId): array
    {
        $statuses = [
            FiscalMutationStatus::UnknownResult->value,
            FiscalMutationStatus::Reconciling->value,
            FiscalMutationStatus::Sent->value,
        ];

        $mutations = FiscalMutationOperation::query()
            ->where('tenant_id', $tenantId)
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
            // Campos internos de contrato, credencial, custo e outros tenants são omitidos.
        ];
    }
}
