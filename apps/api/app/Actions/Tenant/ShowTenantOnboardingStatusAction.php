<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\OnboardingStatusData;
use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalProfile;
use App\Enums\TenantSerproOnboardingStatus;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\FiscalMonitoringRun;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Integra\SerproTenantActionableStatusService;
use App\Support\CurrentTenant;

final readonly class ShowTenantOnboardingStatusAction
{
    /** @var array<string, string> */
    private const ACTIONABLE_LABELS = [
        'COMPLETE_PROFILE' => 'Completar perfil',
        'ACCEPT_CONSENT' => 'Aceitar consentimento',
        'UPLOAD_CERTIFICATE' => 'Enviar certificado',
        'UPLOAD_TERMO' => 'Regularizar Termo de autorização',
        'SIGNATURE_REQUIRED' => 'Assinatura do Termo pendente',
        'REONBOARD_REQUIRED' => 'Reativar integrações',
        'PLATFORM_UNAVAILABLE' => 'Aguardar suporte da plataforma',
        'MISSING_PROFILE' => 'Completar perfil',
        'MISSING_CONSENT' => 'Aceitar consentimento',
        'MISSING_CERTIFICATE' => 'Enviar certificado',
    ];

    public function __construct(
        private CurrentTenant $currentTenant,
        private SerproTenantActionableStatusService $actionableStatus,
        private FiscalModuleAvailabilityService $moduleAvailability,
    ) {}

    public function __invoke(): OnboardingStatusData
    {
        $tenant = $this->currentTenant->tenant();
        $status = $this->actionableStatus->forTenant($tenant);
        $onboarding = $status['onboarding'] ?? [];
        $actions = $this->actions($status, $onboarding);
        $environment = FiscalProfile::configured()->serproEnvironment();
        $procuracaoCounts = ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('environment', $environment->value)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $moduleDecisions = array_map(
            fn (FiscalControlModule $module): array => $this->moduleAvailability
                ->resolve($module, $tenant, FiscalOperationClass::Read)
                ->toArray(),
            FiscalControlModule::cases(),
        );
        $initialRuns = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('trigger', 'SCHEDULED');

        return new OnboardingStatusData([
            'status' => $onboarding['status'] ?? 'incomplete',
            'stage' => $this->onboardingStage((string) ($onboarding['status'] ?? 'incomplete')),
            'actions' => $actions,
            'correlation_id' => $status['correlation_id'] ?? null,
            'message' => $this->message($onboarding, $actions),
            'modules' => $moduleDecisions,
            'procuracoes' => [
                'total_clients' => Client::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('is_active', true)
                    ->count(),
                'by_status' => $procuracaoCounts,
                'verified' => collect([
                    ClientProcuracaoSyncStatus::Authorized->value,
                    ClientProcuracaoSyncStatus::Missing->value,
                    ClientProcuracaoSyncStatus::Expired->value,
                ])->sum(fn (string $itemStatus): int => (int) ($procuracaoCounts[$itemStatus] ?? 0)),
            ],
            'initial_collection' => [
                'queued_at' => $onboarding['initial_collection_queued_at'] ?? null,
                'runs_total' => (clone $initialRuns)->count(),
                'runs_pending' => (clone $initialRuns)
                    ->whereIn('status', ['QUEUED', 'RUNNING'])
                    ->count(),
                'runs_finished' => (clone $initialRuns)
                    ->whereIn('status', ['COMPLETED', 'BLOCKED', 'FAILED', 'SKIPPED'])
                    ->count(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>  $onboarding
     * @return list<array{code: string, label: string, description: ?string, href: null}>
     */
    private function actions(array $status, array $onboarding): array
    {
        $actions = [];
        foreach ($status['actionable'] ?? [] as $item) {
            $code = (string) ($item['code'] ?? 'ACTION_REQUIRED');
            $actions[] = [
                'code' => $code,
                'label' => self::ACTIONABLE_LABELS[$code] ?? 'Ação necessária',
                'description' => isset($item['message']) ? (string) $item['message'] : null,
                'href' => null,
            ];
        }

        if ($actions !== []
            || ! in_array(($onboarding['status'] ?? null), ['incomplete', 'configuring'], true)) {
            return $actions;
        }

        $prerequisites = $status['prerequisites'] ?? [];
        if (! ($prerequisites['profile'] ?? true)) {
            $actions[] = [
                'code' => 'COMPLETE_PROFILE',
                'label' => self::ACTIONABLE_LABELS['COMPLETE_PROFILE'],
                'description' => 'Preencha CNPJ, razão social, e-mail e telefone institucionais.',
                'href' => null,
            ];
        }
        if (! ($prerequisites['consent'] ?? true)) {
            $actions[] = [
                'code' => 'ACCEPT_CONSENT',
                'label' => self::ACTIONABLE_LABELS['ACCEPT_CONSENT'],
                'description' => 'Aceite o consentimento técnico para uso do certificado.',
                'href' => null,
            ];
        }
        if (! ($prerequisites['certificate'] ?? true)) {
            $actions[] = [
                'code' => 'UPLOAD_CERTIFICATE',
                'label' => self::ACTIONABLE_LABELS['UPLOAD_CERTIFICATE'],
                'description' => 'Envie o certificado do escritório.',
                'href' => null,
            ];
        }

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $onboarding
     * @param  list<array{code: string, label: string, description: ?string, href: null}>  $actions
     */
    private function message(array $onboarding, array $actions): ?string
    {
        if (isset($onboarding['actionable']['message'])) {
            return (string) $onboarding['actionable']['message'];
        }

        return $actions === []
            ? null
            : (string) ($actions[0]['description'] ?? null);
    }

    private function onboardingStage(string $status): string
    {
        return match (TenantSerproOnboardingStatus::tryFrom($status)) {
            TenantSerproOnboardingStatus::Configuring,
            TenantSerproOnboardingStatus::Incomplete => 'CONFIGURANDO',
            TenantSerproOnboardingStatus::Validating => 'VALIDANDO',
            TenantSerproOnboardingStatus::Authorizing,
            TenantSerproOnboardingStatus::Provisioning => 'AUTORIZANDO',
            TenantSerproOnboardingStatus::LoadingProxyPowers => 'CARREGANDO_PROCURACOES',
            TenantSerproOnboardingStatus::Syncing => 'SINCRONIZANDO',
            TenantSerproOnboardingStatus::Ready,
            TenantSerproOnboardingStatus::Authorized => 'PRONTO',
            TenantSerproOnboardingStatus::ActionRequired => 'ACAO_NECESSARIA',
            TenantSerproOnboardingStatus::TechnicalError => 'FALHA_TECNICA',
            TenantSerproOnboardingStatus::Revoked => 'REVOGADO',
            default => 'CONFIGURANDO',
        };
    }
}
