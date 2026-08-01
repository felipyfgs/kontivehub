<?php

use App\Enums\ApiRateLimit;
use App\Http\Controllers\Api\V1\Activation\PublicActivationController;
use App\Http\Controllers\Api\V1\Assistant\AssistantChatController;
use App\Http\Controllers\Api\V1\Assistant\AssistantConversationController;
use App\Http\Controllers\Api\V1\Auth\ConfirmPasswordController;
use App\Http\Controllers\Api\V1\Auth\UpdateAccountController;
use App\Http\Controllers\Api\V1\ClientCategoryAssignmentController;
use App\Http\Controllers\Api\V1\ClientCategoryController;
use App\Http\Controllers\Api\V1\ClientContactController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientCredentialController;
use App\Http\Controllers\Api\V1\CnpjLookupController;
use App\Http\Controllers\Api\V1\Communication\AutomationController;
use App\Http\Controllers\Api\V1\Communication\CatalogController;
use App\Http\Controllers\Api\V1\Communication\ContactController;
use App\Http\Controllers\Api\V1\Communication\ConversationBulkOperationController;
use App\Http\Controllers\Api\V1\Communication\ConversationController;
use App\Http\Controllers\Api\V1\Communication\ConversationGatewayController;
use App\Http\Controllers\Api\V1\Communication\ConversationListPreferenceController;
use App\Http\Controllers\Api\V1\Communication\DataController;
use App\Http\Controllers\Api\V1\Communication\FlowController;
use App\Http\Controllers\Api\V1\Communication\FlowRunController;
use App\Http\Controllers\Api\V1\Communication\InboxController;
use App\Http\Controllers\Api\V1\Communication\InboxGatewayController;
use App\Http\Controllers\Api\V1\Communication\ProfilePictureController;
use App\Http\Controllers\Api\V1\CteEmitterPushController;
use App\Http\Controllers\Api\V1\CteOperationsController;
use App\Http\Controllers\Api\V1\DocumentImportBatchController;
use App\Http\Controllers\Api\V1\DteCanaryTenantController;
use App\Http\Controllers\Api\V1\EstablishmentController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\Fiscal\CcmeiMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\DasnSimeiController;
use App\Http\Controllers\Api\V1\Fiscal\DctfwebController;
use App\Http\Controllers\Api\V1\Fiscal\DctfwebMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\DeclarationHubController;
use App\Http\Controllers\Api\V1\Fiscal\DeclarationOperationController;
use App\Http\Controllers\Api\V1\Fiscal\DefisDeclarationsMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\DefisLatestDeclarationMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\DefisSpecificDeclarationMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\FgtsDigitalController;
use App\Http\Controllers\Api\V1\Fiscal\FgtsEsocialController;
use App\Http\Controllers\Api\V1\Fiscal\FiscalCategoryController;
use App\Http\Controllers\Api\V1\Fiscal\FiscalModulePortfolioController;
use App\Http\Controllers\Api\V1\Fiscal\FiscalMonitoringRunController;
use App\Http\Controllers\Api\V1\Fiscal\FiscalMutationController;
use App\Http\Controllers\Api\V1\Fiscal\FiscalSnapshotController;
use App\Http\Controllers\Api\V1\Fiscal\MailboxMessageController;
use App\Http\Controllers\Api\V1\Fiscal\MailboxMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\ManualConsultController;
use App\Http\Controllers\Api\V1\Fiscal\MeiAutomationAttemptController;
use App\Http\Controllers\Api\V1\Fiscal\MeiDasController;
use App\Http\Controllers\Api\V1\Fiscal\MitController;
use App\Http\Controllers\Api\V1\Fiscal\MonitoringCoverageController;
use App\Http\Controllers\Api\V1\Fiscal\MonitoringInsightsController;
use App\Http\Controllers\Api\V1\Fiscal\MonitoringModuleCommunicationController;
use App\Http\Controllers\Api\V1\Fiscal\MonitoringModuleMembershipController;
use App\Http\Controllers\Api\V1\Fiscal\PagtoWebArrecadacaoReceiptController;
use App\Http\Controllers\Api\V1\Fiscal\PagtoWebPaymentCountController;
use App\Http\Controllers\Api\V1\Fiscal\PagtoWebPaymentListController;
use App\Http\Controllers\Api\V1\Fiscal\PgdasdMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\PgmeiMonitoringController;
use App\Http\Controllers\Api\V1\Fiscal\PnrRenunciationController;
use App\Http\Controllers\Api\V1\Fiscal\RegistrationLinkController;
use App\Http\Controllers\Api\V1\Fiscal\SicalcRevenueSupportController;
use App\Http\Controllers\Api\V1\Fiscal\SimplesMeiController;
use App\Http\Controllers\Api\V1\Fiscal\SitfisSituationController;
use App\Http\Controllers\Api\V1\Fiscal\TaxGuideController;
use App\Http\Controllers\Api\V1\Fiscal\TaxInstallmentController;
use App\Http\Controllers\Api\V1\Fiscal\TaxProcessController;
use App\Http\Controllers\Api\V1\FiscalDocumentController;
use App\Http\Controllers\Api\V1\FiscalDocumentQuarantineController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OperationsInboxController;
use App\Http\Controllers\Api\V1\OperationsSummaryController;
use App\Http\Controllers\Api\V1\OutboundCaptureController;
use App\Http\Controllers\Api\V1\OutboundDeadlineController;
use App\Http\Controllers\Api\V1\Platform\FiscalModuleControlController;
use App\Http\Controllers\Api\V1\Platform\InitialOnboardingController;
use App\Http\Controllers\Api\V1\Platform\PlatformOwnerController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantSelectController;
use App\Http\Controllers\Api\V1\Platform\SerproContractController;
use App\Http\Controllers\Api\V1\Platform\SerproDteCanaryController;
use App\Http\Controllers\Api\V1\Platform\SerproPlatformConfigurationController;
use App\Http\Controllers\Api\V1\Platform\SerproPlatformOpsController;
use App\Http\Controllers\Api\V1\Platform\SerproProductionOnboardingController;
use App\Http\Controllers\Api\V1\Platform\SerproUsageAdminController;
use App\Http\Controllers\Api\V1\Platform\TenantAdminController;
use App\Http\Controllers\Api\V1\SavedListFilterController;
use App\Http\Controllers\Api\V1\SerproTenantController;
use App\Http\Controllers\Api\V1\SvrsNfceRecoveryController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\Tenant\TenantMemberController;
use App\Http\Controllers\Api\V1\TenantAutXmlController;
use App\Http\Controllers\Api\V1\TenantFiscalCredentialController;
use App\Http\Controllers\Api\V1\TenantSerproAuthorizationController;
use App\Http\Controllers\Api\V1\TenantSerproUsageController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\TenantSubscriptionController;
use App\Http\Controllers\Api\V1\TenantSwitchController;
use App\Http\Controllers\Api\V1\Work\DashboardController;
use App\Http\Controllers\Api\V1\Work\DepartmentController;
use App\Http\Controllers\Api\V1\Work\ProcessController;
use App\Http\Controllers\Api\V1\Work\ProcessGenerationController;
use App\Http\Controllers\Api\V1\Work\ProcessGroupController;
use App\Http\Controllers\Api\V1\Work\ProcessTemplateCatalogController;
use App\Http\Controllers\Api\V1\Work\ProcessTemplateController;
use App\Http\Controllers\Api\V1\Work\TaskController;
use App\Http\Controllers\Internal\CommunicationGatewayEventController;
use App\Http\Controllers\Internal\CommunicationGatewayMediaController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\EnsureTenantSubscriptionWritable;
use App\Http\Middleware\EnsureWorkRealMembership;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::post('/internal/v1/communication/gateway/events', CommunicationGatewayEventController::class)
    ->middleware(ThrottleRequests::using(ApiRateLimit::InternalCommunicationGateway));
Route::get('/internal/v1/communication/gateway/media/{command}', CommunicationGatewayMediaController::class)
    ->middleware(ThrottleRequests::using(ApiRateLimit::InternalCommunicationGateway));

Route::prefix('v1')->group(function (): void {
    // EMITTER_PUSH — autenticação por token de integração (sem sessão)
    Route::post('/integrations/cte/push', [CteEmitterPushController::class, 'push'])
        ->middleware(ThrottleRequests::using(ApiRateLimit::CteEmitterPush));

    // Ativação pública (sem auth) — token/senha somente no body; Cache-Control no controller
    Route::middleware(ThrottleRequests::using(ApiRateLimit::PublicActivation))->group(function (): void {
        Route::post('/activations/inspect', [PublicActivationController::class, 'inspect']);
        Route::post('/activations/complete', [PublicActivationController::class, 'complete']);
        Route::post('/first-access/complete', [PublicActivationController::class, 'completeFirstAccess']);
    });

    // Onboarding inicial da plataforma (fail-closed; token no body; no-store no controller)
    Route::get('/onboarding/status', [InitialOnboardingController::class, 'status'])
        ->middleware(ThrottleRequests::using(ApiRateLimit::PublicOnboardingStatus));
    Route::post('/onboarding', [InitialOnboardingController::class, 'complete'])
        ->middleware(ThrottleRequests::using(ApiRateLimit::PublicOnboardingCompletion));

    Route::middleware(['auth:sanctum', EnsureActiveUser::class])->group(function (): void {
        Route::get('/me', MeController::class);

        // Troca explícita de tenant (fora de EnsureTenantContext — tenant_id de destino é validado por membership)
        Route::get('/tenants/memberships', [TenantSwitchController::class, 'memberships']);
        Route::post('/tenants/switch', [TenantSwitchController::class, 'switch'])
            ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));

        // Reconfirmação de senha (janela curta) — usada por ações privilegiadas sensíveis
        Route::post('/auth/confirm-password', ConfirmPasswordController::class)
            ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));

        // Identidade global do próprio usuário (independe de Tenant/papel).
        Route::patch('/account', UpdateAccountController::class)
            ->middleware([
                ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                EnsureRecentPasswordConfirmation::class,
            ]);

        // Administração global da plataforma (SEM tenant context de membership).
        // Navegação comum não exige reconfirmação; ações sensíveis exigem senha recente.
        // Ações sensíveis privilegiadas: reconfirmação de senha + demais gates fail-closed.
        Route::middleware([
            EnsurePlatformAdmin::class,
        ])->prefix('platform')->group(function (): void {
            // Seletor global de tenant (platform_privileged; flag default OFF)
            // Rotas estáticas antes de /tenants/{tenant}
            Route::get('/tenants/current', [PlatformTenantSelectController::class, 'current']);
            Route::post('/tenants/select', [PlatformTenantSelectController::class, 'select'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));
            Route::delete('/tenants/select', [PlatformTenantSelectController::class, 'clear'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));

            // Lista do seletor privilegiado (envelope com selected/default)
            Route::get('/tenants/selector', [PlatformTenantSelectController::class, 'index']);
            // Administração de Tenants (criação pendente, detalhe, ativação)
            Route::get('/tenants/admin', [PlatformTenantController::class, 'index']);
            Route::post('/tenants', [PlatformTenantController::class, 'store'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            // Detalhe admin (lifecycle/profile/first_admin) — deve preceder o show comercial genérico.
            Route::get('/tenants/{tenant}', [PlatformTenantController::class, 'show']);
            Route::post('/tenants/{tenant}/activation/regenerate', [PlatformTenantController::class, 'regenerateActivation'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::patch('/tenants/{tenant}/first-admin', [PlatformTenantController::class, 'updateFirstAdmin'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);

            // Proprietário singleton (PLATFORM_ADMIN)
            Route::get('/owner', [PlatformOwnerController::class, 'show']);
            Route::patch('/owner', [PlatformOwnerController::class, 'update'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);

            Route::get('/tenants', [TenantAdminController::class, 'index']);
            Route::patch('/tenants/{tenant}/subscription', [TenantAdminController::class, 'updateSubscription']);

            Route::get('/fiscal/modules', [FiscalModuleControlController::class, 'globalIndex']);
            Route::patch('/fiscal/modules/{module}/restriction', [FiscalModuleControlController::class, 'updateGlobal']);
            Route::get('/tenants/{tenant}/fiscal/modules', [FiscalModuleControlController::class, 'tenantIndex']);
            Route::patch('/tenants/{tenant}/fiscal/modules/{module}/restriction', [FiscalModuleControlController::class, 'updateTenant']);

            // Consolidação e conciliação de consumo SERPRO (ledger)
            Route::get('/serpro-usage/consolidation', [SerproUsageAdminController::class, 'consolidation']);
            Route::post('/serpro-usage/recompute', [SerproUsageAdminController::class, 'recompute']);
            Route::post('/serpro-usage/reconciliations', [SerproUsageAdminController::class, 'registerReconciliation']);

            // Contrato SERPRO global — leitura sanitizada.
            Route::get('/serpro/contracts', [SerproContractController::class, 'index']);
            Route::get('/serpro/contracts/{serproContract}', [SerproContractController::class, 'show']);
            Route::get('/serpro/health', [SerproContractController::class, 'health']);
            Route::get('/serpro/catalog', [SerproContractController::class, 'catalog']);
            Route::get('/serpro/kill-switch', [SerproContractController::class, 'killSwitchStatus']);
            Route::post('/serpro/kill-switch', [SerproContractController::class, 'killSwitch']);
            Route::post('/serpro/breaker/reset', [SerproContractController::class, 'breakerReset']);

            // Configuração global unificada (Proprietário)
            Route::get('/serpro/configuration', [SerproPlatformConfigurationController::class, 'show']);
            Route::get('/serpro/production-onboarding', [SerproProductionOnboardingController::class, 'show']);
            Route::post('/serpro/production-onboarding', [SerproProductionOnboardingController::class, 'store'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedCritical),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/serpro/credential-versions', [SerproPlatformConfigurationController::class, 'storeCredentialVersion']);
            Route::post('/serpro/credential-versions/{serproCredentialVersion}/verify', [SerproPlatformConfigurationController::class, 'verifyCredentialVersion']);
            Route::post('/serpro/credential-versions/{serproCredentialVersion}/test-connection', [SerproPlatformConfigurationController::class, 'testConnection']);
            Route::post('/serpro/credential-versions/{serproCredentialVersion}/activation', [SerproPlatformConfigurationController::class, 'activateCredentialVersion']);
            Route::patch('/serpro/external-gates/{gate}', [SerproPlatformConfigurationController::class, 'updateExternalGate']);
            Route::put('/serpro/usage-limits', [SerproPlatformConfigurationController::class, 'updateUsageLimits']);

            // Credenciais versionadas (leitura), readiness, orçamento e rollout
            Route::get('/serpro/credential-versions', [SerproPlatformOpsController::class, 'listCredentialVersions']);
            Route::get('/serpro/credential-versions/{serproCredentialVersion}', [SerproPlatformOpsController::class, 'showCredentialVersion']);
            Route::get('/serpro/readiness', [SerproPlatformOpsController::class, 'readiness']);
            Route::get('/serpro/metrics', [SerproPlatformOpsController::class, 'metrics']);
            Route::get('/serpro/budgets', [SerproPlatformOpsController::class, 'listBudgets']);
            Route::get('/serpro/rollouts', [SerproPlatformOpsController::class, 'listRollouts']);
            Route::post('/serpro/rollouts', [SerproPlatformOpsController::class, 'requestRollout'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
            Route::post('/serpro/rollouts/{serproRolloutApproval}/approve', [SerproPlatformOpsController::class, 'approveRollout'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/serpro/rollouts/{serproRolloutApproval}/reject', [SerproPlatformOpsController::class, 'rejectRollout'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));

            // Canário DTE controlado (Proprietário) — sem payload fiscal na resposta
            Route::get('/serpro/dte-canary', [SerproDteCanaryController::class, 'summary']);
            Route::post('/serpro/dte-canary', [SerproDteCanaryController::class, 'create'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::get('/serpro/dte-canary/{serproDteCanaryRequest}', [SerproDteCanaryController::class, 'show']);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/target', [SerproDteCanaryController::class, 'selectTarget'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/approve-owner', [SerproDteCanaryController::class, 'approveOwner'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/execute', [SerproDteCanaryController::class, 'execute'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/reconcile', [SerproDteCanaryController::class, 'reconcile'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/promote-limited', [SerproDteCanaryController::class, 'promoteLimited'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/serpro/dte-canary/disable', [SerproDteCanaryController::class, 'disable'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive),
                    EnsureRecentPasswordConfirmation::class,
                ]);
        });

        Route::middleware([
            EnsureTenantContext::class,
            EnsureTenantSubscriptionWritable::class,
        ])->group(function (): void {
            // Assinatura/limites do tenant atual (leitura liberada mesmo suspenso — middleware só bloqueia mutações)
            Route::get('/tenant/subscription', [TenantSubscriptionController::class, 'show']);

            Route::prefix('communication')->group(function (): void {
                Route::get('/inboxes', [InboxController::class, 'index']);
                Route::post('/inboxes', [InboxController::class, 'store']);
                Route::patch('/inboxes/{inbox}', [InboxController::class, 'update']);
                Route::delete('/inboxes/{inbox}', [InboxController::class, 'destroy'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
                Route::put('/inboxes/{inbox}/members', [InboxController::class, 'replaceMembers']);
                Route::post('/inboxes/{inbox}/session/logout', [InboxController::class, 'revoke'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
                Route::get('/inboxes/{inbox}/session/status', [InboxGatewayController::class, 'sessionStatus']);
                Route::post('/inboxes/{inbox}/session/connect', [InboxController::class, 'startPairing'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
                Route::post('/inboxes/{inbox}/session/disconnect', [InboxGatewayController::class, 'disconnect']);
                Route::put('/inboxes/{inbox}/session/passive', [InboxGatewayController::class, 'passive']);
                Route::post('/inboxes/{inbox}/session/pair-phone', [InboxGatewayController::class, 'pairPhone'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
                Route::post('/inboxes/{inbox}/session/passkey/respond', [InboxGatewayController::class, 'respondPasskey'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
                Route::post('/inboxes/{inbox}/session/passkey/confirm', [InboxGatewayController::class, 'confirmPasskey'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
                Route::put('/inboxes/{inbox}/presence', [InboxGatewayController::class, 'globalPresence']);
                Route::put('/inboxes/{inbox}/default-disappearing', [InboxGatewayController::class, 'defaultDisappearing']);
                Route::post('/inboxes/{inbox}/app-state/sync', [InboxGatewayController::class, 'syncState']);
                Route::post('/inboxes/{inbox}/app-state/mark-clean', [InboxGatewayController::class, 'markStateClean']);
                Route::get('/inboxes/{inbox}/blocklist', [InboxGatewayController::class, 'blocklist']);
                Route::put('/inboxes/{inbox}/blocklist', [InboxGatewayController::class, 'updateBlocklist']);
                Route::get('/inboxes/{inbox}/privacy', [InboxGatewayController::class, 'privacy']);
                Route::put('/inboxes/{inbox}/privacy', [InboxGatewayController::class, 'updatePrivacy']);
                Route::post('/inboxes/{inbox}/contacts/check', [InboxGatewayController::class, 'checkUsers']);
                Route::post('/inboxes/{inbox}/contacts/info', [InboxGatewayController::class, 'userInfo']);
                Route::post('/inboxes/{inbox}/contacts/business-profiles', [InboxGatewayController::class, 'businessProfiles']);
                Route::post('/inboxes/{inbox}/contacts/profile-picture', [InboxGatewayController::class, 'profilePicture'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::CommunicationProfilePicture));
                Route::post('/inboxes/{inbox}/contacts/qr-link', [InboxGatewayController::class, 'contactQrLink']);
                Route::post('/inboxes/{inbox}/contacts/qr-resolve', [InboxGatewayController::class, 'resolveContactQr']);
                Route::post('/inboxes/{inbox}/contacts/business-link-resolve', [InboxGatewayController::class, 'resolveBusinessLink']);
                Route::patch('/settings', [InboxController::class, 'updateTenantSettings']);

                Route::get('/automation-policies', [AutomationController::class, 'index']);
                Route::put('/automation-policies', [AutomationController::class, 'upsert']);
                Route::get('/clients/{client}/automation-recipients', [AutomationController::class, 'recipients']);
                Route::put('/clients/{client}/automation-recipients', [AutomationController::class, 'updateRecipients']);

                Route::get('/contacts', [ContactController::class, 'index']);
                Route::get('/profile-pictures/{profile}/{version}', [ProfilePictureController::class, 'show'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::CommunicationProfilePicture))
                    ->whereNumber('version')
                    ->name('communication.profile-pictures.show');
                Route::post('/contacts/search', [ContactController::class, 'search'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive))
                    // Busca por telefone é leitura; o POST mantém PII fora da URL.
                    ->withoutMiddleware(EnsureTenantSubscriptionWritable::class);
                Route::post('/contacts', [ContactController::class, 'store']);
                Route::get('/contacts/{contact}', [ContactController::class, 'show']);
                Route::get('/contacts/{contact}/shared-content', [ContactController::class, 'sharedContent']);
                Route::patch('/contacts/{contact}', [ContactController::class, 'update']);
                Route::post('/contacts/{contact}/identities', [ContactController::class, 'addIdentity']);
                Route::post('/identities/{identity}/links', [ContactController::class, 'linkIdentity']);
                Route::delete('/identities/{identity}/links/{link}', [ContactController::class, 'unlinkIdentity']);

                Route::get('/conversations', [ConversationController::class, 'index']);
                Route::post('/conversations', [ConversationController::class, 'store'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::CommunicationMessageSend));
                Route::get('/conversation-list-preferences', [ConversationListPreferenceController::class, 'show']);
                Route::put('/conversation-list-preferences', [ConversationListPreferenceController::class, 'update']);
                Route::post('/conversation-bulk-operations', [ConversationBulkOperationController::class, 'store'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
                Route::get('/conversation-bulk-operations/{operation}', [ConversationBulkOperationController::class, 'show']);
                Route::get('/conversation-bulk-operations/{operation}/items', [ConversationBulkOperationController::class, 'items']);
                Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
                Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
                Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
                Route::get('/conversations/{conversation}/shared-content', [ConversationController::class, 'sharedContent']);
                Route::put('/conversations/{conversation}/read-state', [ConversationController::class, 'updateReadState']);
                Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::CommunicationMessageSend));
                Route::put('/conversations/{conversation}/messages/{message}/edit', [ConversationGatewayController::class, 'edit']);
                Route::delete('/conversations/{conversation}/messages/{message}', [ConversationGatewayController::class, 'revoke']);
                Route::put('/conversations/{conversation}/messages/{message}/reaction', [ConversationGatewayController::class, 'react']);
                Route::post('/conversations/{conversation}/messages/{message}/poll-votes', [ConversationGatewayController::class, 'votePoll']);
                Route::post('/conversations/{conversation}/messages/{message}/receipts', [ConversationGatewayController::class, 'receipt']);
                Route::post('/conversations/{conversation}/messages/{message}/history', [ConversationGatewayController::class, 'history'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
                Route::post('/conversations/{conversation}/messages/{message}/recovery', [ConversationGatewayController::class, 'recovery'])
                    ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
                Route::post('/conversations/{conversation}/presence/subscribe', [ConversationGatewayController::class, 'subscribePresence']);
                Route::put('/conversations/{conversation}/presence', [ConversationGatewayController::class, 'chatPresence']);
                Route::put('/conversations/{conversation}/disappearing', [ConversationGatewayController::class, 'disappearing']);
                Route::put('/conversations/{conversation}/state', [ConversationGatewayController::class, 'state']);
                Route::put('/conversations/{conversation}/labels/{label}', [ConversationController::class, 'addLabel']);
                Route::delete('/conversations/{conversation}/labels/{label}', [ConversationController::class, 'removeLabel']);

                Route::get('/labels', [CatalogController::class, 'labels']);
                Route::get('/outbound-capabilities', [CatalogController::class, 'outboundCapabilities']);
                Route::post('/labels', [CatalogController::class, 'storeLabel']);
                Route::delete('/labels/{label}', [CatalogController::class, 'deleteLabel']);
                Route::get('/canned-responses', [CatalogController::class, 'cannedResponses']);
                Route::post('/canned-responses', [CatalogController::class, 'storeCannedResponse']);
                Route::put('/canned-responses/{canned}', [CatalogController::class, 'updateCannedResponse']);
                Route::post('/canned-responses/{canned}/duplicate', [CatalogController::class, 'duplicateCannedResponse']);
                Route::post('/canned-responses/{canned}/deactivate', [CatalogController::class, 'deactivateCannedResponse']);
                Route::post('/canned-responses/{canned}/render', [CatalogController::class, 'renderCannedResponse']);
                Route::delete('/canned-responses/{canned}', [CatalogController::class, 'deleteCannedResponse']);

                Route::get('/flows', [FlowController::class, 'index']);
                Route::post('/flows', [FlowController::class, 'store']);
                Route::get('/flows/{flow}', [FlowController::class, 'show']);
                Route::patch('/flows/{flow}', [FlowController::class, 'update']);
                Route::delete('/flows/{flow}', [FlowController::class, 'destroy']);
                Route::get('/flows/{flow}/draft', [FlowController::class, 'showDraft']);
                Route::put('/flows/{flow}/draft', [FlowController::class, 'updateDraft']);
                Route::post('/flows/{flow}/validate', [FlowController::class, 'validateGraph']);
                Route::post('/flows/{flow}/dry-run', [FlowController::class, 'dryRun']);
                Route::post('/flows/{flow}/preview', [FlowController::class, 'previewGraph']);
                Route::post('/flows/{flow}/publish', [FlowController::class, 'publish']);
                Route::post('/flows/{flow}/clone', [FlowController::class, 'cloneFlow']);
                Route::post('/flows/{flow}/versions/{version}/clone', [FlowController::class, 'cloneVersion']);
                Route::get('/flows/{flow}/bindings', [FlowController::class, 'indexBindings']);
                Route::post('/flows/{flow}/bindings', [FlowController::class, 'storeBinding']);
                Route::patch('/flow-bindings/{binding}', [FlowController::class, 'updateBinding']);
                Route::post('/flow-bindings/{binding}/enable', [FlowController::class, 'enableBinding']);
                Route::post('/flow-bindings/{binding}/disable', [FlowController::class, 'disableBinding']);
                Route::delete('/flow-bindings/{binding}', [FlowController::class, 'destroyBinding']);
                Route::get('/flow-runs', [FlowRunController::class, 'index']);
                Route::get('/flow-runs/{run}', [FlowRunController::class, 'show']);
                Route::post('/flow-runs/{run}/pause', [FlowRunController::class, 'pause']);
                Route::post('/flow-runs/{run}/resume', [FlowRunController::class, 'resume']);
                Route::post('/flow-runs/{run}/handoff', [FlowRunController::class, 'handoff']);
                Route::post('/flow-runs/{run}/stop', [FlowRunController::class, 'stop']);
                Route::post('/flow-runs/{run}/restart', [FlowRunController::class, 'restart']);

                Route::get('/events', [DataController::class, 'sync']);
                Route::get('/attachments/{attachment}/download', [DataController::class, 'downloadAttachment']);
                Route::get('/attachments/{attachment}/preview', [DataController::class, 'previewAttachment']);
                Route::get('/contacts/{contact}/export', [DataController::class, 'exportContact']);
                Route::delete('/contacts/{contact}/personal-data', [DataController::class, 'purgeContact']);
            });

            // Tenant SERPRO namespace canônico (/api/v1/serpro/*) — tenant_id só via CurrentTenant
            Route::get('/serpro/authorization', [SerproTenantController::class, 'authorization']);
            Route::get('/serpro/readiness', [SerproTenantController::class, 'readiness']);
            Route::get('/serpro/health', [SerproTenantController::class, 'health']);
            Route::get('/serpro/usage', [SerproTenantController::class, 'usageSummary']);
            Route::get('/serpro/usage/entries', [SerproTenantController::class, 'usageEntries']);

            // Onboarding Integra: Autor, Termo, procurações (sem XML/PFX/tokens na resposta)
            Route::get('/tenant/serpro-authorization', [TenantSerproAuthorizationController::class, 'show']);
            Route::post('/tenant/serpro-authorization/author', [TenantSerproAuthorizationController::class, 'configureAuthor']);
            Route::post('/tenant/serpro-authorization/termo/draft', [TenantSerproAuthorizationController::class, 'generateTermoDraft']);
            Route::get('/tenant/serpro-authorization/termo/draft', [TenantSerproAuthorizationController::class, 'downloadTermoDraft'])
                ->middleware(EnsureRecentPasswordConfirmation::class);
            Route::post('/tenant/serpro-authorization/termo', [TenantSerproAuthorizationController::class, 'uploadTermo']);
            Route::post('/tenant/serpro-authorization/termo/sign-with-certificate', [TenantSerproAuthorizationController::class, 'signTermoManagedCertificate']);
            Route::post('/tenant/serpro-authorization/refresh-token', [TenantSerproAuthorizationController::class, 'refreshToken']);
            Route::get('/tenant/serpro-authorization/proxy-powers', [TenantSerproAuthorizationController::class, 'listProxyPowers']);
            Route::post('/tenant/serpro-authorization/proxy-powers', [TenantSerproAuthorizationController::class, 'importProxyPower']);
            Route::post('/tenant/serpro-authorization/proxy-powers/sync', [TenantSerproAuthorizationController::class, 'syncProxyPowers']);
            Route::post('/tenant/serpro-authorization/eligibility', [TenantSerproAuthorizationController::class, 'eligibility']);
            Route::get('/tenant/serpro-authorization/health', [TenantSerproAuthorizationController::class, 'platformHealth']);

            // Consumo/franquia SERPRO do tenant (sem orçamento global nem outros tenants)
            Route::get('/tenant/serpro-usage', [TenantSerproUsageController::class, 'summary']);
            Route::get('/tenant/serpro-usage/entries', [TenantSerproUsageController::class, 'entries']);

            // Canário DTE — confirmação Tenant ADMIN + resultado fiscal (membership)
            Route::get('/serpro/dte-canary/pending', [DteCanaryTenantController::class, 'pending']);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/confirm', [DteCanaryTenantController::class, 'confirmParticipation'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::get('/serpro/dte-canary/{serproDteCanaryRequest}/result', [DteCanaryTenantController::class, 'result']);

            // Núcleo de monitoramento fiscal (tenant-scoped; mutações off por padrão no adapter)
            Route::get('/fiscal/categories', [FiscalCategoryController::class, 'indexCategories']);
            Route::get('/fiscal/category-links', [FiscalCategoryController::class, 'indexLinks']);
            Route::post('/fiscal/category-links', [FiscalCategoryController::class, 'associate']);
            Route::post('/fiscal/category-links/batch', [FiscalCategoryController::class, 'associateBatch']);
            Route::get('/fiscal/monitoring/membership', [MonitoringModuleMembershipController::class, 'index']);
            Route::post('/fiscal/monitoring/membership/exclude', [MonitoringModuleMembershipController::class, 'exclude']);
            Route::post('/fiscal/monitoring/membership/include', [MonitoringModuleMembershipController::class, 'include']);
            Route::get('/fiscal/runs', [FiscalMonitoringRunController::class, 'index']);
            Route::post('/fiscal/runs', [FiscalMonitoringRunController::class, 'store']);
            Route::get('/fiscal/runs/{run}', [FiscalMonitoringRunController::class, 'show']);
            Route::get('/fiscal/mei-automation/attempts/{attempt}', [MeiAutomationAttemptController::class, 'show']);
            Route::get('/fiscal/mei-automation/attempts/{attempt}/artifacts/{artifact}/download', [MeiAutomationAttemptController::class, 'download']);
            Route::get('/fiscal/snapshots', [FiscalSnapshotController::class, 'index']);
            Route::get('/fiscal/snapshots/{snapshot}', [FiscalSnapshotController::class, 'show']);
            Route::get('/fiscal/findings', [FiscalSnapshotController::class, 'findings']);
            Route::get('/fiscal/pending-items', [FiscalSnapshotController::class, 'pending']);
            Route::get('/fiscal/evidence/{evidence}/download', [FiscalSnapshotController::class, 'downloadEvidence']);

            // Contrato público das superfícies: saídas documentadas e cobertura Trial.
            Route::get('/fiscal/monitoring/coverage', MonitoringCoverageController::class);
            Route::get('/fiscal/monitoring/insights', MonitoringInsightsController::class);

            // Read model de carteira por módulo (overview + clients; tenant_id só via membership)
            Route::get('/fiscal/modules/{module}/overview', [FiscalModulePortfolioController::class, 'overview']);
            Route::get('/fiscal/modules/{module}/clients', [FiscalModulePortfolioController::class, 'clients']);

            // DCTFWeb / MIT (evidências versionadas; transmissão/encerramento atrás de flags mutantes OFF)
            Route::get('/fiscal/dctfweb/declarations', [DctfwebController::class, 'indexDeclarations']);
            Route::get('/fiscal/dctfweb/declarations/{declaration}', [DctfwebController::class, 'showDeclaration']);
            Route::post('/fiscal/dctfweb/events', [DctfwebController::class, 'ingestEvent']);
            Route::post('/fiscal/dctfweb/consult', [DctfwebController::class, 'enqueueConsult']);
            Route::post('/fiscal/dctfweb/transmit', [DctfwebController::class, 'transmit'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::get('/fiscal/mit/apuracoes', [MitController::class, 'index']);
            Route::get('/fiscal/mit/apuracoes/{apuracao}', [MitController::class, 'show']);
            Route::get('/fiscal/mit/lista-apuracoes', [MitController::class, 'indexListaApuracoes']);
            Route::post('/fiscal/mit/consult', [MitController::class, 'enqueueConsult']);
            Route::post('/fiscal/mit/lista-apuracoes', [MitController::class, 'enqueueListaApuracoes']);
            Route::post('/fiscal/mit/encerrar', [MitController::class, 'encerrar'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));

            // Parcelamentos SN/MEI (modalidades catalogadas; mutantes OFF)
            Route::get('/fiscal/installments/modalities', [TaxInstallmentController::class, 'modalities']);
            Route::get('/fiscal/installments/orders', [TaxInstallmentController::class, 'orders']);
            Route::get('/fiscal/installments/orders/{order}', [TaxInstallmentController::class, 'showOrder']);
            Route::get('/fiscal/installments/parcels', [TaxInstallmentController::class, 'parcels']);
            Route::get('/fiscal/installments/guides', [TaxInstallmentController::class, 'guides']);
            Route::post('/fiscal/installments/monitor', [TaxInstallmentController::class, 'monitor']);
            Route::post('/fiscal/installments/runs', [TaxInstallmentController::class, 'enqueue']);

            // Operações fiscais mutantes (OFF por default; senha recente + confirmação + idempotência)
            Route::post('/fiscal/mutations/preflight', [FiscalMutationController::class, 'preflight'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));
            Route::post('/fiscal/mutations', [FiscalMutationController::class, 'execute'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::get('/fiscal/mutations/{mutation}', [FiscalMutationController::class, 'show']);
            Route::post('/fiscal/mutations/{mutation}/reconcile', [FiscalMutationController::class, 'reconcile'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);

            // Situação Fiscal (SITFIS) — snapshot com idade; refresh respeita TTL
            Route::get('/fiscal/sitfis', [SitfisSituationController::class, 'show']);
            Route::post('/fiscal/sitfis/refresh', [SitfisSituationController::class, 'refresh']);
            Route::get('/fiscal/sitfis/clients/{client}/history', [SitfisSituationController::class, 'history']);

            // Explorador de consultas manuais (somente leitura; GET local; POST confirmado)
            Route::get('/fiscal/manual-consults', [ManualConsultController::class, 'index']);
            Route::post('/fiscal/manual-consults', [ManualConsultController::class, 'store'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));

            // Cadastro/Vínculos (PNR Contador) — listagem + detalhe + refresh explícito
            Route::get('/fiscal/registrations', [RegistrationLinkController::class, 'index']);
            Route::get('/fiscal/clients/{clientId}/registrations', [RegistrationLinkController::class, 'showForClient']);
            Route::post('/fiscal/clients/{clientId}/registrations/refresh', [RegistrationLinkController::class, 'refresh'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));

            // PNR Contador — leituras de renúncia explicitamente acionadas pelo usuário.
            Route::get('/fiscal/clients/{clientId}/pnr-renunciations', [PnrRenunciationController::class, 'index']);
            Route::post('/fiscal/clients/{clientId}/pnr-renunciations/history', [PnrRenunciationController::class, 'history'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::post('/fiscal/clients/{clientId}/pnr-renunciations/status', [PnrRenunciationController::class, 'status'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::post('/fiscal/clients/{clientId}/pnr-renunciations/receipt', [PnrRenunciationController::class, 'receipt'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));

            // Processos fiscais (e-Processo)
            Route::get('/fiscal/tax-processes', [TaxProcessController::class, 'index']);
            Route::get('/fiscal/clients/{clientId}/tax-processes', [TaxProcessController::class, 'showForClient']);
            Route::post('/fiscal/clients/{clientId}/tax-processes/refresh', [TaxProcessController::class, 'refresh'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
            Route::get('/fiscal/tax-processes/{id}', [TaxProcessController::class, 'show'])
                ->whereNumber('id');

            // Caixa Postal / DTE (tenant-scoped; conteúdo restrito; triagem ≠ leitura oficial)
            Route::get('/fiscal/mailbox/messages', [MailboxMessageController::class, 'index']);
            Route::get('/fiscal/mailbox/messages/{message}', [MailboxMessageController::class, 'show']);
            Route::patch('/fiscal/mailbox/messages/{message}/triage', [MailboxMessageController::class, 'triage']);
            Route::get('/fiscal/mailbox/messages/{message}/body', [MailboxMessageController::class, 'downloadBody']);
            Route::get('/fiscal/mailbox/messages/{message}/attachments/{attachment}', [MailboxMessageController::class, 'downloadAttachment']);
            Route::get('/fiscal/mailbox/state', [MailboxMessageController::class, 'state']);
            Route::get('/fiscal/mailbox/alerts', [MailboxMessageController::class, 'alerts']);
            Route::get('/fiscal/mailbox/monitoring', [MailboxMonitoringController::class, 'show']);
            Route::patch('/fiscal/mailbox/monitoring', [MailboxMonitoringController::class, 'update']);
            Route::post('/fiscal/mailbox/monitoring/preview', [MailboxMonitoringController::class, 'preview']);
            Route::post('/fiscal/mailbox/monitoring/sync', [MailboxMonitoringController::class, 'sync'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
            Route::get('/fiscal/mailbox/messages/{message}/detail-preview', [MailboxMonitoringController::class, 'detailPreview']);
            Route::post('/fiscal/mailbox/messages/{message}/detail', [MailboxMonitoringController::class, 'detail'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));

            // Central de declarações (catálogo versionado, projeções, recibos — sem guias)
            Route::get('/fiscal/declarations/catalog', [DeclarationHubController::class, 'catalog']);
            Route::get('/fiscal/declarations/summary', [DeclarationHubController::class, 'summary']);
            Route::get('/fiscal/declarations', [DeclarationHubController::class, 'index']);
            Route::post('/fiscal/declarations/operations/{action}/read', [DeclarationOperationController::class, 'read'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));
            Route::post('/fiscal/declarations/operations/{action}/preflight', [DeclarationOperationController::class, 'preflight'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));
            Route::post('/fiscal/declarations/operations/{action}/execute', [DeclarationOperationController::class, 'execute'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::get('/fiscal/declarations/operations/mutations/{mutation}', [DeclarationOperationController::class, 'show'])
                ->whereNumber('mutation');
            Route::post('/fiscal/declarations/operations/mutations/{mutation}/reconcile', [DeclarationOperationController::class, 'reconcile'])
                ->whereNumber('mutation')
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/fiscal/declarations/project', [DeclarationHubController::class, 'project']);
            Route::post('/fiscal/declarations/calendar', [DeclarationHubController::class, 'publishCalendar']);
            Route::get('/fiscal/declarations/{projection}', [DeclarationHubController::class, 'show']);
            Route::post('/fiscal/declarations/{projection}/evidences', [DeclarationHubController::class, 'attachEvidence']);
            Route::get('/fiscal/declarations/{projection}/evidences/{evidence}', [DeclarationHubController::class, 'showEvidence']);

            // Central de guias (mutações OFF por default — FeatureFlags guias)
            Route::get('/fiscal/guides', [TaxGuideController::class, 'index']);
            Route::post('/fiscal/guides/preflight', [TaxGuideController::class, 'preflight']);
            Route::post('/fiscal/guides', [TaxGuideController::class, 'store'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
            Route::get('/fiscal/guides/downloads/{token}', [TaxGuideController::class, 'download']);
            Route::get('/fiscal/guides/{guide}', [TaxGuideController::class, 'show']);
            Route::post('/fiscal/guides/{guide}/download-token', [TaxGuideController::class, 'issueDownloadToken']);
            Route::post('/fiscal/guides/{guide}/payment-confirmations', [TaxGuideController::class, 'confirmPayment']);
            Route::post('/fiscal/guides/{guide}/reconcile', [TaxGuideController::class, 'reconcile']);

            // SICALC 5.2 — metadados de preenchimento de DARF (somente leitura).
            Route::get('/fiscal/guides/revenue-support/clients/{client}/history', [SicalcRevenueSupportController::class, 'history']);
            Route::post('/fiscal/guides/revenue-support/clients/{client}/consult', [SicalcRevenueSupportController::class, 'consult']);
            Route::get('/fiscal/guides/payment-count/clients/{client}/history', [PagtoWebPaymentCountController::class, 'history']);
            Route::post('/fiscal/guides/payment-count/clients/{client}/consult', [PagtoWebPaymentCountController::class, 'consult']);
            Route::get('/fiscal/guides/payments/clients/{client}/history', [PagtoWebPaymentListController::class, 'history']);
            Route::post('/fiscal/guides/payments/clients/{client}/consult', [PagtoWebPaymentListController::class, 'consult']);
            Route::get('/fiscal/guides/receipts/clients/{client}/history', [PagtoWebArrecadacaoReceiptController::class, 'history']);
            Route::post('/fiscal/guides/receipts/clients/{client}/request', [PagtoWebArrecadacaoReceiptController::class, 'request'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::get('/fiscal/guides/receipts/clients/{client}/{receipt}/download', [PagtoWebArrecadacaoReceiptController::class, 'download']);

            // Simples Nacional / MEI (tenant-scoped; mutações bloqueadas no piloto)
            Route::get('/fiscal/simples-mei/catalog', [SimplesMeiController::class, 'catalog']);
            Route::get('/fiscal/simples-mei/clients/{client}/regimes', [SimplesMeiController::class, 'regimes']);
            Route::get('/fiscal/simples-mei/clients/{client}/competences', [SimplesMeiController::class, 'competences']);
            Route::get('/fiscal/simples-mei/clients/{client}/snapshots', [SimplesMeiController::class, 'snapshots']);
            Route::get('/fiscal/simples-mei/clients/{client}/regime-calendar', [SimplesMeiController::class, 'regimeCalendar']);
            Route::get('/fiscal/simples-mei/clients/{client}/regime-options', [SimplesMeiController::class, 'regimeOptions']);
            Route::get('/fiscal/simples-mei/clients/{client}/regime-resolutions', [SimplesMeiController::class, 'regimeResolutions']);
            Route::post('/fiscal/simples-mei/regime-calendar/consult', [SimplesMeiController::class, 'consultRegimeCalendar']);
            Route::post('/fiscal/simples-mei/regime-option/consult', [SimplesMeiController::class, 'consultRegimeOption']);
            Route::post('/fiscal/simples-mei/regime-resolution/consult', [SimplesMeiController::class, 'consultRegimeResolution']);

            // PGDAS-D: histórico local, documentos, comunicação TEMPLATE_ONLY
            // Contrato canônico do SPA: /fiscal/simples-mei/pgdasd/...
            Route::get('/fiscal/simples-mei/pgdasd/clients/{client}/history', [PgdasdMonitoringController::class, 'history']);
            Route::post('/fiscal/simples-mei/pgdasd/clients/{client}/documents', [PgdasdMonitoringController::class, 'collectDocuments']);
            Route::get('/fiscal/simples-mei/pgdasd/artifacts/{artifact}/download', [PgdasdMonitoringController::class, 'downloadArtifactById']);
            Route::patch('/fiscal/simples-mei/pgdasd/clients/{client}/communication-preference', [PgdasdMonitoringController::class, 'updatePreferences']);
            Route::patch('/fiscal/simples-mei/pgdasd/communication-preferences/bulk', [PgdasdMonitoringController::class, 'batchPreferences']);
            Route::get('/fiscal/simples-mei/pgdasd/clients/{client}/communication-preview', [PgdasdMonitoringController::class, 'preview']);
            Route::get('/fiscal/simples-mei/pgdasd/clients/{client}/communications', [PgdasdMonitoringController::class, 'tracking']);
            Route::post('/fiscal/simples-mei/pgdasd/clients/{client}/communication-send', [PgdasdMonitoringController::class, 'send']);

            // PGMEI — dívida ativa (histórico local + consulta manual + comunicação template)
            Route::get('/fiscal/simples-mei/pgmei/clients/{client}/history', [PgmeiMonitoringController::class, 'history']);
            Route::post('/fiscal/simples-mei/pgmei/consult', [PgmeiMonitoringController::class, 'consult']);
            Route::post('/fiscal/simples-mei/pgmei/das/preflight', [MeiDasController::class, 'preflight'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));
            Route::post('/fiscal/simples-mei/pgmei/das', [MeiDasController::class, 'store'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::patch('/fiscal/simples-mei/pgmei/clients/{client}/communication-preference', [PgmeiMonitoringController::class, 'updatePreferences']);
            Route::patch('/fiscal/simples-mei/pgmei/communication-preferences/bulk', [PgmeiMonitoringController::class, 'batchPreferences']);
            Route::get('/fiscal/simples-mei/pgmei/clients/{client}/communication-preview', [PgmeiMonitoringController::class, 'preview']);
            Route::get('/fiscal/simples-mei/pgmei/clients/{client}/communications', [PgmeiMonitoringController::class, 'tracking']);
            Route::post('/fiscal/simples-mei/pgmei/clients/{client}/communication-send', [PgmeiMonitoringController::class, 'send']);

            // DASN-SIMEI — histórico público com cobertura explícita e consulta assíncrona.
            Route::get('/fiscal/simples-mei/dasn-simei/clients/{client}/history', [DasnSimeiController::class, 'history']);
            Route::post('/fiscal/simples-mei/dasn-simei/consult', [DasnSimeiController::class, 'consult']);

            // CCMEI — consulta explícita e histórico sanitizado, ambos tenant-scoped.
            Route::get('/fiscal/simples-mei/ccmei/clients/{client}/history', [CcmeiMonitoringController::class, 'history']);
            Route::post('/fiscal/simples-mei/ccmei/clients/{client}/consult', [CcmeiMonitoringController::class, 'consult']);
            Route::get('/fiscal/simples-mei/ccmei/clients/{client}/issued-certificates', [CcmeiMonitoringController::class, 'issuedCertificates']);
            Route::post('/fiscal/simples-mei/ccmei/clients/{client}/issued-certificates', [CcmeiMonitoringController::class, 'issueCertificate'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::get('/fiscal/simples-mei/ccmei/clients/{client}/issued-certificates/{certificate}/download', [CcmeiMonitoringController::class, 'downloadIssuedCertificate']);
            Route::get('/fiscal/simples-mei/ccmei/registration-status/clients/{client}/history', [CcmeiMonitoringController::class, 'registrationStatusHistory']);
            Route::post('/fiscal/simples-mei/ccmei/registration-status/clients/{client}/consult', [CcmeiMonitoringController::class, 'consultRegistrationStatus']);

            // DEFIS 142 — histórico sanitizado e coleta manual confirmada.
            Route::get('/fiscal/simples-mei/defis/clients/{client}/history', [DefisDeclarationsMonitoringController::class, 'history']);
            Route::post('/fiscal/simples-mei/defis/clients/{client}/consult', [DefisDeclarationsMonitoringController::class, 'consult']);

            // DEFIS 143 — última declaração/recibo no cofre, com descritores sanitizados.
            Route::get('/fiscal/simples-mei/defis/latest-declaration/clients/{client}/history', [DefisLatestDeclarationMonitoringController::class, 'history']);
            Route::post('/fiscal/simples-mei/defis/latest-declaration/clients/{client}/consult', [DefisLatestDeclarationMonitoringController::class, 'consult']);
            Route::get('/fiscal/simples-mei/defis/latest-declaration/artifacts/{artifact}/download', [DefisLatestDeclarationMonitoringController::class, 'download']);

            // DEFIS 144 — declaração específica/recibo por referência opaca da listagem 142.
            Route::get('/fiscal/simples-mei/defis/specific-declaration/clients/{client}/history', [DefisSpecificDeclarationMonitoringController::class, 'history']);
            Route::post('/fiscal/simples-mei/defis/specific-declaration/clients/{client}/consult', [DefisSpecificDeclarationMonitoringController::class, 'consult']);
            Route::get('/fiscal/simples-mei/defis/specific-declaration/artifacts/{artifact}/download', [DefisSpecificDeclarationMonitoringController::class, 'download']);

            // DCTFWeb — histórico local, evidências, comunicação TEMPLATE_ONLY (sem SERPRO implícito)
            Route::get('/fiscal/dctfweb/clients/{client}/history', [DctfwebMonitoringController::class, 'history']);
            Route::get('/fiscal/dctfweb/clients/{client}/evidence/{evidence}/download', [DctfwebMonitoringController::class, 'downloadEvidence']);
            Route::get('/fiscal/dctfweb/evidence/{evidence}/download', [DctfwebMonitoringController::class, 'downloadEvidenceById']);
            Route::patch('/fiscal/dctfweb/clients/{client}/communication-preference', [DctfwebMonitoringController::class, 'updatePreferences']);
            Route::patch('/fiscal/dctfweb/communication-preferences/bulk', [DctfwebMonitoringController::class, 'batchPreferences']);
            Route::get('/fiscal/dctfweb/clients/{client}/communication-preview', [DctfwebMonitoringController::class, 'preview']);
            Route::get('/fiscal/dctfweb/clients/{client}/communications', [DctfwebMonitoringController::class, 'tracking']);
            Route::post('/fiscal/dctfweb/clients/{client}/communication-send', [DctfwebMonitoringController::class, 'send']);

            // Comunicação transversal (SITFIS / FGTS / MIT)
            Route::patch('/fiscal/{module}/clients/{client}/communication-preference', [MonitoringModuleCommunicationController::class, 'updatePreferences'])
                ->whereIn('module', ['sitfis', 'fgts', 'mit']);
            Route::get('/fiscal/{module}/clients/{client}/communication-preview', [MonitoringModuleCommunicationController::class, 'preview'])
                ->whereIn('module', ['sitfis', 'fgts', 'mit']);
            Route::get('/fiscal/{module}/clients/{client}/communications', [MonitoringModuleCommunicationController::class, 'tracking'])
                ->whereIn('module', ['sitfis', 'fgts', 'mit']);
            Route::post('/fiscal/{module}/clients/{client}/communication-send', [MonitoringModuleCommunicationController::class, 'send'])
                ->whereIn('module', ['sitfis', 'fgts', 'mit']);

            // FGTS parcial via eSocial (cobertura explícita; sem portal FGTS Digital)
            Route::get('/fiscal/fgts/coverage', [FgtsEsocialController::class, 'coverage']);
            Route::get('/fiscal/fgts/readiness', [FgtsEsocialController::class, 'readiness']);
            Route::get('/fiscal/fgts/competences', [FgtsEsocialController::class, 'competences']);
            Route::get('/fiscal/fgts/competences/{status}', [FgtsEsocialController::class, 'showCompetence']);
            Route::get('/fiscal/fgts/events', [FgtsEsocialController::class, 'events']);
            Route::post('/fiscal/fgts/sync', [FgtsEsocialController::class, 'sync']);
            Route::post('/fiscal/fgts/sync-now', [FgtsEsocialController::class, 'syncNow']);

            // FGTS Digital portal — guia/PDF/pagamento; driver disabled por default.
            Route::get('/fiscal/fgts/digital/coverage', [FgtsDigitalController::class, 'coverage']);
            Route::get('/fiscal/fgts/digital/readiness', [FgtsDigitalController::class, 'readiness']);
            Route::get('/fiscal/fgts/digital/runs', [FgtsDigitalController::class, 'runs']);
            Route::post('/fiscal/fgts/digital/sync', [FgtsDigitalController::class, 'sync'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));
            Route::post('/fiscal/fgts/digital/sync-now', [FgtsDigitalController::class, 'syncNow'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::post('/fiscal/fgts/digital/preview', [FgtsDigitalController::class, 'preview'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::post('/fiscal/fgts/digital/previews/{run}/emit', [FgtsDigitalController::class, 'emit'])
                ->whereNumber('run')
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::post('/fiscal/fgts/digital/sessions/import', [FgtsDigitalController::class, 'importSession'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));
            Route::post('/fiscal/fgts/digital/representations', [FgtsDigitalController::class, 'storeRepresentation'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedSensitive));

            Route::get('/clients', [ClientController::class, 'index']);
            Route::get('/cnpj/{cnpj}/lookup', CnpjLookupController::class)
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate));
            Route::post('/clients', [ClientController::class, 'store']);
            Route::patch('/clients/bulk-status', [ClientController::class, 'bulkStatus']);
            Route::patch('/clients/bulk-categories', [ClientCategoryAssignmentController::class, 'bulk']);
            Route::get('/client-categories', [ClientCategoryController::class, 'index']);
            Route::post('/client-categories', [ClientCategoryController::class, 'store']);
            Route::patch('/client-categories/{clientCategory}', [ClientCategoryController::class, 'update']);
            Route::put('/clients/{client}/categories', [ClientCategoryAssignmentController::class, 'replace']);
            Route::get('/clients/{client}', [ClientController::class, 'show']);
            Route::patch('/clients/{client}', [ClientController::class, 'update']);
            Route::patch('/clients/{client}/custom-fields/{customField}', [ClientController::class, 'updateCustomField']);
            Route::post('/clients/{client}/refresh-registration', [ClientController::class, 'refreshRegistration'])
                ->middleware(ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard));

            Route::post('/clients/{client}/establishments', [EstablishmentController::class, 'store']);
            Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update']);

            Route::get('/clients/{client}/contacts', [ClientContactController::class, 'index']);
            Route::post('/clients/{client}/contacts', [ClientContactController::class, 'store']);
            Route::patch('/clients/{client}/contacts/{contact}', [ClientContactController::class, 'update']);
            Route::delete('/clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroy']);

            Route::get('/clients/{client}/credential', [ClientCredentialController::class, 'show']);
            Route::post('/clients/{client}/credential', [ClientCredentialController::class, 'store']);

            // Identidade fiscal usada pelo canal autXML.
            Route::get('/tenant/fiscal-identity', [TenantFiscalCredentialController::class, 'showIdentity']);
            Route::post('/tenant/fiscal-identity', [TenantFiscalCredentialController::class, 'storeIdentity']);

            // Configuração unificada /settings: perfil, consentimento, certificado (sem download)
            Route::get('/tenant/settings', [TenantSettingsController::class, 'show']);
            Route::patch('/tenant/settings/profile', [TenantSettingsController::class, 'updateProfile']);
            Route::get('/tenant/settings/consent', [TenantSettingsController::class, 'showConsent']);
            Route::post('/tenant/settings/consent', [TenantSettingsController::class, 'grantConsent']);
            Route::post('/tenant/settings/consent/revoke', [TenantSettingsController::class, 'revokeConsent']);
            // certificado: só ADMIN (policy) + senha do PFX. Sem reconfirmação de senha de
            // login no formulário — o material sensível é a senha do certificado, não a da conta.
            Route::get('/tenant/settings/certificate', [TenantSettingsController::class, 'showCertificate']);
            Route::post('/tenant/settings/certificate', [TenantSettingsController::class, 'storeCertificate']);
            Route::post('/tenant/settings/certificate/replace', [TenantSettingsController::class, 'replaceCertificate']);
            Route::post('/tenant/settings/certificate/remove', [TenantSettingsController::class, 'removeCertificate']);
            Route::post('/tenant/settings/refresh-integration', [TenantSettingsController::class, 'refreshIntegration']);
            Route::get('/tenant/settings/monitor-schedules', [TenantSettingsController::class, 'listMonitorSchedules']);
            Route::put('/tenant/settings/monitor-schedules/{monitorKey}', [TenantSettingsController::class, 'updateMonitorSchedule']);
            Route::get('/tenant/settings/onboarding-status', [TenantSettingsController::class, 'onboardingStatus']);

            // Equipe do escritório — exige membership ADMIN real (checado no serviço)
            Route::get('/tenant/members', [TenantMemberController::class, 'index']);
            Route::post('/tenant/members', [TenantMemberController::class, 'store'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedModerate),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::patch('/tenant/members/{membership}', [TenantMemberController::class, 'update'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::patch('/tenant/members/{membership}/recipient', [TenantMemberController::class, 'updateRecipient'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/tenant/members/{membership}/deactivate', [TenantMemberController::class, 'deactivate'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/tenant/members/{membership}/reactivate', [TenantMemberController::class, 'reactivate'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/tenant/members/{membership}/activation/regenerate', [TenantMemberController::class, 'regenerateActivation'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                    EnsureRecentPasswordConfirmation::class,
                ]);

            // Onboarding autXML + cursor central (sem reset de NSU)
            Route::get('/tenant/autxml', [TenantAutXmlController::class, 'overview']);
            Route::get('/tenant/autxml/cursor', [TenantAutXmlController::class, 'cursor']);
            Route::post('/tenant/autxml/enrollments', [TenantAutXmlController::class, 'enroll']);
            Route::post('/tenant/autxml/enrollments/{enrollment}/confirm', [TenantAutXmlController::class, 'confirm']);
            Route::post('/tenant/autxml/enrollments/{enrollment}/inactivate', [TenantAutXmlController::class, 'inactivate']);

            // Tokens de integração CT-e (EMITTER_PUSH) — admin com senha recente emite/revoga; sem recuperação
            Route::get('/tenant/integration-tokens', [CteEmitterPushController::class, 'listTokens']);
            Route::post('/tenant/integration-tokens', [CteEmitterPushController::class, 'issueToken'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::CteIntegrationTokenAdministration),
                    EnsureRecentPasswordConfirmation::class,
                ]);
            Route::post('/tenant/integration-tokens/{token}/revoke', [CteEmitterPushController::class, 'revokeToken'])
                ->middleware([
                    ThrottleRequests::using(ApiRateLimit::CteIntegrationTokenAdministration),
                    EnsureRecentPasswordConfirmation::class,
                ]);

            // Operação CT-e: onboarding, dois streams, cobertura e pendências sanitizadas
            Route::get('/cte/onboarding', [CteOperationsController::class, 'onboarding']);
            Route::get('/cte/health', [CteOperationsController::class, 'health']);
            Route::get('/cte/coverage', [CteOperationsController::class, 'coverage']);
            Route::get('/cte/pending', [CteOperationsController::class, 'pending']);
            Route::post('/cte/repairs', [CteOperationsController::class, 'repairKnownNsu']);

            // Catálogo unificado Documentos (canônico)
            Route::get('/documents', [FiscalDocumentController::class, 'index']);
            Route::get('/documents/by-client', [FiscalDocumentController::class, 'byClient']);
            Route::get('/documents/insights', [FiscalDocumentController::class, 'insights']);
            Route::get('/documents/import-batches', [DocumentImportBatchController::class, 'index']);
            Route::post('/documents/import-batches', [DocumentImportBatchController::class, 'store']);
            Route::get('/documents/import-batches/{batch}', [DocumentImportBatchController::class, 'show']);
            Route::get('/documents/import-batches/{batch}/items', [DocumentImportBatchController::class, 'items']);
            Route::post('/documents/import-batches/{batch}/items/{item}/retry', [DocumentImportBatchController::class, 'retryItem']);
            Route::get('/documents/import-batches/{batch}/export.csv', [DocumentImportBatchController::class, 'exportCsv']);
            Route::get('/documents/{accessKey}', [FiscalDocumentController::class, 'show']);
            Route::get('/documents/{accessKey}/xml', [FiscalDocumentController::class, 'downloadXml']);
            Route::post('/documents/{accessKey}/unlock-xml', [FiscalDocumentController::class, 'unlockXml']);
            Route::post('/documents/{accessKey}/manifestations', [FiscalDocumentController::class, 'manifest']);

            Route::get('/sync-runs', [SyncController::class, 'history']);
            Route::post('/sync-runs', [SyncController::class, 'trigger']);

            Route::get('/exports', [ExportController::class, 'index']);
            Route::post('/exports', [ExportController::class, 'store']);
            Route::get('/exports/{export}/download', [ExportController::class, 'download']);

            // Filtros salvos de lista (personal | tenant; tenant_id só via CurrentTenant)
            Route::get('/list-filters', [SavedListFilterController::class, 'index']);
            Route::post('/list-filters', [SavedListFilterController::class, 'store']);
            Route::patch('/list-filters/{listFilter}', [SavedListFilterController::class, 'update']);
            Route::delete('/list-filters/{listFilter}', [SavedListFilterController::class, 'destroy']);

            Route::get('/operations/summary', OperationsSummaryController::class);
            Route::get('/operations/inbox', OperationsInboxController::class);

            // Assistente de produto (OpenAI via API; fail-closed)
            Route::prefix('assistant')
                ->middleware(ThrottleRequests::using(ApiRateLimit::AssistantAccess))
                ->group(function (): void {
                    Route::get('/conversations', [AssistantConversationController::class, 'index']);
                    Route::post('/conversations', [AssistantConversationController::class, 'store']);
                    Route::get('/conversations/{conversation}/messages', [AssistantConversationController::class, 'messages']);
                    Route::post('/conversations/{conversation}/chat', [AssistantChatController::class, 'chat'])
                        ->middleware(ThrottleRequests::using(ApiRateLimit::AssistantChat));
                    Route::post('/conversations/{conversation}/approve-tool', [AssistantChatController::class, 'approve'])
                        ->middleware([
                            ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                            EnsureWorkRealMembership::class,
                        ]);
                    Route::post('/conversations/{conversation}/deny-tool', [AssistantChatController::class, 'deny'])
                        ->middleware([
                            ThrottleRequests::using(ApiRateLimit::AuthenticatedStandard),
                            EnsureWorkRealMembership::class,
                        ]);
                });

            // ── Work: processos operacionais (plano de dados; sem SERPRO/ADN/SEFAZ) ──
            // Leitura: membership ou platform_privileged. Mutação/export: membership real.
            // @see config/work_route_matrix.php
            Route::prefix('work')->group(function (): void {
                Route::get('/departments', [DepartmentController::class, 'index']);
                Route::get('/template-catalog', [ProcessTemplateCatalogController::class, 'index']);
                Route::get('/templates', [ProcessTemplateController::class, 'index']);
                Route::get('/templates/{template}', [ProcessTemplateController::class, 'show']);
                Route::get('/templates/{template}/recurrence', [ProcessTemplateController::class, 'showRecurrence']);
                Route::get('/templates/{template}/generation-batches', [ProcessTemplateController::class, 'generationBatches']);
                Route::get('/generation-batches/{batch}', [ProcessGenerationController::class, 'show']);
                Route::get('/queue', [TaskController::class, 'queue']);
                Route::get('/process-groups', [ProcessGroupController::class, 'index']);
                Route::get('/processes', [ProcessController::class, 'index']);
                Route::get('/processes/{process}', [ProcessController::class, 'show']);
                Route::get('/processes/{process}/timeline', [ProcessController::class, 'timeline']);
                Route::get('/tasks/{task}', [TaskController::class, 'show']);
                Route::get('/tasks/{task}/evidences/{evidence}/download', [TaskController::class, 'downloadEvidence']);
                Route::get('/kpis', [DashboardController::class, 'kpis']);
                Route::get('/calendar', [DashboardController::class, 'calendar']);
                Route::get('/calendar/day', [DashboardController::class, 'calendarDay']);
                Route::get('/exports/{export}', [DashboardController::class, 'showExport']);
                Route::get('/exports/{export}/download', [DashboardController::class, 'downloadExport']);

                Route::middleware([EnsureWorkRealMembership::class])->group(function (): void {
                    Route::post('/departments', [DepartmentController::class, 'store']);
                    Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
                    Route::post('/departments/{department}/assign-membership', [DepartmentController::class, 'assignMembership']);

                    Route::post('/templates', [ProcessTemplateController::class, 'store']);
                    Route::post('/template-catalog/{catalogKey}/install', [ProcessTemplateCatalogController::class, 'install']);
                    Route::patch('/templates/{template}', [ProcessTemplateController::class, 'update']);
                    Route::patch('/templates/{template}/recurrence', [ProcessTemplateController::class, 'updateRecurrence']);
                    Route::post('/templates/{template}/preview', [ProcessGenerationController::class, 'preview']);
                    Route::post('/generation-batches/{batch}/confirm', [ProcessGenerationController::class, 'confirm']);
                    Route::post('/generation-batches/{batch}/retry', [ProcessGenerationController::class, 'retry']);

                    Route::post('/processes', [ProcessController::class, 'store']);
                    Route::patch('/processes/{process}', [ProcessController::class, 'update']);
                    Route::post('/processes/bulk', [ProcessController::class, 'bulk']);
                    Route::post('/processes/{process}/archive', [ProcessController::class, 'archive']);
                    Route::post('/processes/{process}/comments', [ProcessController::class, 'comment']);

                    Route::post('/processes/{process}/tasks', [TaskController::class, 'storeOnProcess']);
                    Route::post('/processes/{process}/tasks/reorder', [TaskController::class, 'reorder']);
                    Route::patch('/tasks/{task}/structure', [TaskController::class, 'updateStructure']);
                    Route::post('/tasks/{task}/start', [TaskController::class, 'start']);
                    Route::post('/tasks/{task}/block', [TaskController::class, 'block']);
                    Route::post('/tasks/{task}/resume', [TaskController::class, 'resume']);
                    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
                    Route::post('/tasks/{task}/dispense', [TaskController::class, 'dispense']);
                    Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen']);
                    Route::post('/tasks/{task}/claim', [TaskController::class, 'claim']);
                    Route::post('/tasks/{task}/assign', [TaskController::class, 'assign']);
                    Route::post('/tasks/{task}/comments', [TaskController::class, 'comment']);
                    Route::post('/tasks/{task}/evidences', [TaskController::class, 'uploadEvidence']);
                    Route::delete('/tasks/{task}/evidences/{evidence}', [TaskController::class, 'removeEvidence']);
                    Route::post('/tasks/bulk', [TaskController::class, 'bulk']);

                    Route::post('/exports', [DashboardController::class, 'createExport']);
                });
            });

            // Quarentena fiscal (sem XML bruto)
            Route::get('/operations/quarantine', [FiscalDocumentQuarantineController::class, 'index']);
            Route::post('/operations/quarantine/{quarantine}/resolve', [FiscalDocumentQuarantineController::class, 'resolve']);

            // Captura de saídas MA (nNF — nunca NSU)
            Route::get('/outbound/profiles', [OutboundCaptureController::class, 'indexProfiles']);
            Route::get('/outbound/profiles/{profile}', [OutboundCaptureController::class, 'showProfile']);
            Route::post('/outbound/establishments/{establishment}/seed', [OutboundCaptureController::class, 'storeSeed']);
            Route::get('/outbound/profiles/{profile}/csc', [OutboundCaptureController::class, 'showCsc']);
            Route::post('/outbound/profiles/{profile}/csc', [OutboundCaptureController::class, 'storeCsc']);
            Route::post('/outbound/profiles/{profile}/activate', [OutboundCaptureController::class, 'activate']);
            Route::post('/outbound/profiles/{profile}/package', [OutboundCaptureController::class, 'uploadPackage']);
            Route::get('/outbound/profiles/{profile}/series', [OutboundCaptureController::class, 'listSeries']);
            Route::get('/outbound/series/{series}/numbers', [OutboundCaptureController::class, 'listNumbers']);
            Route::post('/outbound/series/{series}/reset', [OutboundCaptureController::class, 'resetSeries']);
            Route::post('/outbound/series/{series}/trigger-query', [OutboundCaptureController::class, 'triggerQuery']);
            Route::get('/outbound/runs', [OutboundCaptureController::class, 'listRuns']);
            Route::get('/outbound/kill-switch', [OutboundCaptureController::class, 'killSwitchStatus']);
            Route::post('/outbound/kill-switch', [OutboundCaptureController::class, 'killSwitch']);

            // Fechamento mensal / capacidade (prazo operacional — dispatch off por default)
            Route::get('/outbound/deadline/competence', [OutboundDeadlineController::class, 'competenceSummary']);
            Route::get('/outbound/deadline/capacity', [OutboundDeadlineController::class, 'capacityForecast']);
            Route::get('/outbound/deadline/pending', [OutboundDeadlineController::class, 'pendingItems']);
            Route::get('/outbound/deadline/contingency-batch', [OutboundDeadlineController::class, 'contingencyBatch']);
            Route::get('/outbound/deadline/metrics', [OutboundDeadlineController::class, 'metrics']);
            Route::post('/outbound/deadline/confirm-partial', [OutboundDeadlineController::class, 'confirmPartialExport']);
            Route::post('/outbound/deadline/export', [OutboundDeadlineController::class, 'exportMonthly']);
            Route::post('/outbound/deadline/advance-target', [OutboundDeadlineController::class, 'advanceTarget']);

            // Canal SVRS NFC-e XML (flags off por padrão)
            Route::get('/outbound/svrs-nfce/summary', [SvrsNfceRecoveryController::class, 'channelSummary']);
            Route::get('/outbound/svrs-portal/egress', [SvrsNfceRecoveryController::class, 'egressCohortHealth']);
            Route::post('/outbound/svrs-portal/egress/extend-cooldown', [SvrsNfceRecoveryController::class, 'extendEgressCooldown']);
            Route::post('/outbound/svrs-portal/egress/select-canary', [SvrsNfceRecoveryController::class, 'selectEgressCanary']);
            Route::post('/outbound/svrs-portal/egress/elevate-budget', [SvrsNfceRecoveryController::class, 'refuseBudgetElevation']);
            Route::get('/outbound/svrs-nfce/recoveries', [SvrsNfceRecoveryController::class, 'index']);
            Route::post('/outbound/svrs-nfce/recoveries', [SvrsNfceRecoveryController::class, 'enqueue']);
            Route::get('/outbound/svrs-nfce/recoveries/{recovery}/attempts', [SvrsNfceRecoveryController::class, 'attempts']);
            Route::post('/outbound/svrs-nfce/recoveries/{recovery}/retry', [SvrsNfceRecoveryController::class, 'retry']);
            Route::get('/outbound/svrs-nfce/profiles/{profile}/summary', [SvrsNfceRecoveryController::class, 'profileSummary']);
            Route::get('/outbound/svrs-nfce/kill-switch', [SvrsNfceRecoveryController::class, 'killSwitchStatus']);
            Route::post('/outbound/svrs-nfce/kill-switch', [SvrsNfceRecoveryController::class, 'killSwitch']);
            Route::get('/outbound/svrs-nfce/breaker', [SvrsNfceRecoveryController::class, 'breakerStatus']);
            Route::post('/outbound/svrs-nfce/breaker/reset', [SvrsNfceRecoveryController::class, 'breakerReset']);
        });
    });
});
