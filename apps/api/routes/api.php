<?php

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
use App\Http\Controllers\Api\V1\Communication\CommunicationAutomationController;
use App\Http\Controllers\Api\V1\Communication\CommunicationCatalogController;
use App\Http\Controllers\Api\V1\Communication\CommunicationContactController;
use App\Http\Controllers\Api\V1\Communication\CommunicationConversationController;
use App\Http\Controllers\Api\V1\Communication\CommunicationConversationGatewayController;
use App\Http\Controllers\Api\V1\Communication\CommunicationDataController;
use App\Http\Controllers\Api\V1\Communication\CommunicationFlowController;
use App\Http\Controllers\Api\V1\Communication\CommunicationFlowRunController;
use App\Http\Controllers\Api\V1\Communication\CommunicationInboxController;
use App\Http\Controllers\Api\V1\Communication\CommunicationInboxGatewayController;
use App\Http\Controllers\Api\V1\CteEmitterPushController;
use App\Http\Controllers\Api\V1\CteOperationsController;
use App\Http\Controllers\Api\V1\DocumentImportBatchController;
use App\Http\Controllers\Api\V1\DocumentImportController;
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
use App\Http\Controllers\Api\V1\Fiscal\PagtowebArrecadacaoReceiptController;
use App\Http\Controllers\Api\V1\Fiscal\PagtowebPaymentCountController;
use App\Http\Controllers\Api\V1\Fiscal\PagtowebPaymentListController;
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
use App\Http\Controllers\Api\V1\FiscalDocumentQuarantineController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\Office\OfficeMemberController;
use App\Http\Controllers\Api\V1\OfficeAutXmlController;
use App\Http\Controllers\Api\V1\OfficeFiscalCredentialController;
use App\Http\Controllers\Api\V1\OfficeSerproAuthorizationController;
use App\Http\Controllers\Api\V1\OfficeSerproUsageController;
use App\Http\Controllers\Api\V1\OfficeSettingsController;
use App\Http\Controllers\Api\V1\OfficeSubscriptionController;
use App\Http\Controllers\Api\V1\OperationsInboxController;
use App\Http\Controllers\Api\V1\OperationsSummaryController;
use App\Http\Controllers\Api\V1\OutboundCaptureController;
use App\Http\Controllers\Api\V1\OutboundDeadlineController;
use App\Http\Controllers\Api\V1\Platform\FiscalModuleControlController;
use App\Http\Controllers\Api\V1\Platform\InitialOnboardingController;
use App\Http\Controllers\Api\V1\Platform\PlatformOfficeController;
use App\Http\Controllers\Api\V1\Platform\PlatformOfficeSelectController;
use App\Http\Controllers\Api\V1\Platform\PlatformOwnerController;
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
use App\Http\Controllers\Api\V1\TenantSwitchController;
use App\Http\Controllers\Api\V1\Work\OperationalDashboardController;
use App\Http\Controllers\Api\V1\Work\OperationalProcessController;
use App\Http\Controllers\Api\V1\Work\OperationalProcessGroupController;
use App\Http\Controllers\Api\V1\Work\OperationalTaskController;
use App\Http\Controllers\Api\V1\Work\ProcessGenerationController;
use App\Http\Controllers\Api\V1\Work\ProcessTemplateCatalogController;
use App\Http\Controllers\Api\V1\Work\ProcessTemplateController;
use App\Http\Controllers\Api\V1\Work\WorkDepartmentController;
use App\Http\Controllers\Internal\CommunicationGatewayEventController;
use App\Http\Controllers\Internal\CommunicationGatewayMediaController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureOfficeContext;
use App\Http\Middleware\EnsureOfficeSubscriptionWritable;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
use App\Http\Middleware\EnsureWorkRealMembership;
use Illuminate\Support\Facades\Route;

Route::post('/internal/v1/communication/gateway/events', CommunicationGatewayEventController::class)
    ->middleware('throttle:600,1');
Route::get('/internal/v1/communication/gateway/media/{command}', CommunicationGatewayMediaController::class)
    ->middleware('throttle:600,1');

Route::prefix('v1')->group(function (): void {
    // EMITTER_PUSH — autenticação por token de integração (sem sessão)
    Route::post('/integrations/cte/push', [CteEmitterPushController::class, 'push'])
        ->middleware('throttle:'.(int) config('sefaz.cte_emitter_push.rate_limit_per_minute', 30).',1');

    // Ativação pública (sem auth) — token/senha somente no body; Cache-Control no controller
    Route::middleware('throttle:20,1')->group(function (): void {
        Route::post('/activations/inspect', [PublicActivationController::class, 'inspect']);
        Route::post('/activations/complete', [PublicActivationController::class, 'complete']);
        Route::post('/first-access/complete', [PublicActivationController::class, 'completeFirstAccess']);
    });

    // Onboarding inicial da plataforma (fail-closed; token no body; no-store no controller)
    Route::get('/onboarding/status', [InitialOnboardingController::class, 'status'])
        ->middleware('throttle:20,1');
    Route::post('/onboarding', [InitialOnboardingController::class, 'complete'])
        ->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', EnsureActiveUser::class])->group(function (): void {
        Route::get('/me', MeController::class);

        // Troca explícita de tenant (fora de EnsureOfficeContext — office_id de destino é validado por membership)
        Route::get('/tenants/memberships', [TenantSwitchController::class, 'memberships']);
        Route::post('/tenants/switch', [TenantSwitchController::class, 'switch'])
            ->middleware('throttle:30,1');

        // Reconfirmação de senha (janela curta) — usada por ações privilegiadas sensíveis
        Route::post('/auth/confirm-password', ConfirmPasswordController::class)
            ->middleware('throttle:10,1');

        // Identidade global do próprio usuário (independe de Office/papel).
        Route::patch('/account', UpdateAccountController::class)
            ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);

        // Administração global da plataforma (SEM office context de membership).
        // Navegação comum NÃO exige TOTP (spec acesso-global-platform-admin).
        // Ações sensíveis privilegiadas: reconfirmação de senha + demais gates fail-closed.
        Route::middleware([
            EnsurePlatformAdmin::class,
        ])->prefix('platform')->group(function (): void {
            // Seletor global de office (platform_privileged; flag default OFF)
            // Rotas estáticas antes de /offices/{office}
            Route::get('/offices/current', [PlatformOfficeSelectController::class, 'current']);
            Route::post('/offices/select', [PlatformOfficeSelectController::class, 'select'])
                ->middleware('throttle:30,1');
            Route::delete('/offices/select', [PlatformOfficeSelectController::class, 'clear'])
                ->middleware('throttle:30,1');

            // Lista do seletor privilegiado (envelope com selected/default)
            Route::get('/offices/selector', [PlatformOfficeSelectController::class, 'index']);
            // Compat: GET /offices permanece o seletor (testes existentes)
            Route::get('/offices', [PlatformOfficeSelectController::class, 'index']);

            // Administração de Offices (criação pendente, detalhe, ativação)
            Route::get('/offices/admin', [PlatformOfficeController::class, 'index']);
            Route::post('/offices', [PlatformOfficeController::class, 'store'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::get('/offices/{office}', [PlatformOfficeController::class, 'show']);
            Route::post('/offices/{office}/activation/regenerate', [PlatformOfficeController::class, 'regenerateActivation'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::patch('/offices/{office}/first-admin', [PlatformOfficeController::class, 'updateFirstAdmin'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);

            // Proprietário singleton (PLATFORM_ADMIN)
            Route::get('/owner', [PlatformOwnerController::class, 'show']);
            Route::patch('/owner', [PlatformOwnerController::class, 'update'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);

            Route::get('/tenants', [TenantAdminController::class, 'index']);
            Route::get('/tenants/{office}', [TenantAdminController::class, 'show']);
            Route::patch('/tenants/{office}/subscription', [TenantAdminController::class, 'updateSubscription']);

            Route::get('/fiscal/modules', [FiscalModuleControlController::class, 'globalIndex']);
            Route::patch('/fiscal/modules/{module}/restriction', [FiscalModuleControlController::class, 'updateGlobal']);
            Route::get('/tenants/{office}/fiscal/modules', [FiscalModuleControlController::class, 'officeIndex']);
            Route::patch('/tenants/{office}/fiscal/modules/{module}/restriction', [FiscalModuleControlController::class, 'updateOffice']);

            // Consolidação e conciliação de consumo SERPRO (ledger)
            Route::get('/serpro-usage/consolidation', [SerproUsageAdminController::class, 'consolidation']);
            Route::post('/serpro-usage/recompute', [SerproUsageAdminController::class, 'recompute']);
            Route::post('/serpro-usage/reconciliations', [SerproUsageAdminController::class, 'registerReconciliation']);

            // Contrato SERPRO global — leitura sanitizada; mutações legadas removidas (410)
            Route::get('/serpro/contracts', [SerproContractController::class, 'index']);
            Route::get('/serpro/contracts/{serproContract}', [SerproContractController::class, 'show']);
            Route::post('/serpro/contracts', [SerproPlatformConfigurationController::class, 'legacyMutationRemoved']);
            Route::post('/serpro/contracts/{serproContract}/activate', [SerproPlatformConfigurationController::class, 'legacyMutationRemoved']);
            Route::post('/serpro/contracts/{serproContract}/deactivate', [SerproPlatformConfigurationController::class, 'legacyMutationRemoved']);
            Route::post('/serpro/contracts/{serproContract}/block', [SerproPlatformConfigurationController::class, 'legacyMutationRemoved']);
            Route::get('/serpro/health', [SerproContractController::class, 'health']);
            Route::get('/serpro/catalog', [SerproContractController::class, 'catalog']);
            Route::get('/serpro/kill-switch', [SerproContractController::class, 'killSwitchStatus']);
            Route::post('/serpro/kill-switch', [SerproContractController::class, 'killSwitch']);
            Route::post('/serpro/breaker/reset', [SerproContractController::class, 'breakerReset']);

            // Configuração global unificada (Proprietário)
            Route::get('/serpro/configuration', [SerproPlatformConfigurationController::class, 'show']);
            Route::get('/serpro/production-onboarding', [SerproProductionOnboardingController::class, 'show']);
            Route::post('/serpro/production-onboarding', [SerproProductionOnboardingController::class, 'store'])
                ->middleware(['throttle:5,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/serpro/credential-versions', [SerproPlatformConfigurationController::class, 'storeCredentialVersion']);
            Route::post('/serpro/credential-versions/{serproCredentialVersion}/verify', [SerproPlatformConfigurationController::class, 'verifyCredentialVersion']);
            Route::post('/serpro/credential-versions/{serproCredentialVersion}/test-connection', [SerproPlatformConfigurationController::class, 'testConnection']);
            Route::post('/serpro/credential-versions/{serproCredentialVersion}/cutover', [SerproPlatformConfigurationController::class, 'cutoverCredentialVersion']);
            Route::patch('/serpro/external-gates/{gate}', [SerproPlatformConfigurationController::class, 'updateExternalGate']);
            Route::put('/serpro/usage-limits', [SerproPlatformConfigurationController::class, 'updateUsageLimits']);

            // Credenciais versionadas (leitura), readiness, orçamento e rollout
            Route::get('/serpro/credential-versions', [SerproPlatformOpsController::class, 'listCredentialVersions']);
            Route::get('/serpro/credential-versions/{serproCredentialVersion}', [SerproPlatformOpsController::class, 'showCredentialVersion']);
            Route::post('/serpro/credential-versions/{serproCredentialVersion}/approvals', [SerproPlatformOpsController::class, 'approveCredentialVersion']);
            Route::get('/serpro/readiness', [SerproPlatformOpsController::class, 'readiness']);
            Route::get('/serpro/metrics', [SerproPlatformOpsController::class, 'metrics']);
            Route::get('/serpro/budgets', [SerproPlatformOpsController::class, 'listBudgets']);
            Route::get('/serpro/rollouts', [SerproPlatformOpsController::class, 'listRollouts']);
            Route::post('/serpro/rollouts', [SerproPlatformOpsController::class, 'requestRollout']);
            Route::post('/serpro/rollouts/{serproRolloutApproval}/approve', [SerproPlatformOpsController::class, 'approveRollout']);
            Route::post('/serpro/rollouts/{serproRolloutApproval}/reject', [SerproPlatformOpsController::class, 'rejectRollout']);

            // Canário DTE controlado (Proprietário) — sem payload fiscal na resposta
            Route::get('/serpro/dte-canary', [SerproDteCanaryController::class, 'summary']);
            Route::post('/serpro/dte-canary', [SerproDteCanaryController::class, 'create'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::get('/serpro/dte-canary/{serproDteCanaryRequest}', [SerproDteCanaryController::class, 'show']);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/target', [SerproDteCanaryController::class, 'selectTarget'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/approve-owner', [SerproDteCanaryController::class, 'approveOwner'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/execute', [SerproDteCanaryController::class, 'execute'])
                ->middleware(['throttle:10,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/reconcile', [SerproDteCanaryController::class, 'reconcile'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/promote-limited', [SerproDteCanaryController::class, 'promoteLimited'])
                ->middleware(['throttle:10,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/serpro/dte-canary/disable', [SerproDteCanaryController::class, 'disable'])
                ->middleware(['throttle:10,1', EnsureRecentPasswordConfirmation::class]);
        });

        Route::middleware([
            EnsureOfficeContext::class,
            EnsureOfficeSubscriptionWritable::class,
        ])->group(function (): void {
            // Assinatura/limites do office atual (leitura liberada mesmo suspenso — middleware só bloqueia mutações)
            Route::get('/office/subscription', [OfficeSubscriptionController::class, 'show']);

            Route::prefix('communication')->group(function (): void {
                Route::get('/inboxes', [CommunicationInboxController::class, 'index']);
                Route::post('/inboxes', [CommunicationInboxController::class, 'store']);
                Route::patch('/inboxes/{inbox}', [CommunicationInboxController::class, 'update']);
                Route::delete('/inboxes/{inbox}', [CommunicationInboxController::class, 'destroy'])
                    ->middleware('throttle:10,1');
                Route::put('/inboxes/{inbox}/members', [CommunicationInboxController::class, 'replaceMembers']);
                Route::post('/inboxes/{inbox}/pairing', [CommunicationInboxController::class, 'startPairing'])
                    ->middleware('throttle:10,1');
                Route::get('/inboxes/{inbox}/pairing', [CommunicationInboxController::class, 'pairing']);
                Route::delete('/inboxes/{inbox}/session', [CommunicationInboxController::class, 'revoke'])
                    ->middleware('throttle:10,1');
                Route::post('/inboxes/{inbox}/session/logout', [CommunicationInboxController::class, 'revoke'])
                    ->middleware('throttle:10,1');
                Route::get('/inboxes/{inbox}/session/status', [CommunicationInboxGatewayController::class, 'sessionStatus']);
                Route::post('/inboxes/{inbox}/session/connect', [CommunicationInboxController::class, 'startPairing']);
                Route::post('/inboxes/{inbox}/session/disconnect', [CommunicationInboxGatewayController::class, 'disconnect']);
                Route::post('/inboxes/{inbox}/session/reset', [CommunicationInboxGatewayController::class, 'reset']);
                Route::put('/inboxes/{inbox}/session/passive', [CommunicationInboxGatewayController::class, 'passive']);
                Route::post('/inboxes/{inbox}/session/pair-phone', [CommunicationInboxGatewayController::class, 'pairPhone'])
                    ->middleware('throttle:10,1');
                Route::post('/inboxes/{inbox}/session/passkey/respond', [CommunicationInboxGatewayController::class, 'respondPasskey'])
                    ->middleware('throttle:20,1');
                Route::post('/inboxes/{inbox}/session/passkey/confirm', [CommunicationInboxGatewayController::class, 'confirmPasskey'])
                    ->middleware('throttle:20,1');
                Route::put('/inboxes/{inbox}/presence', [CommunicationInboxGatewayController::class, 'globalPresence']);
                Route::put('/inboxes/{inbox}/default-disappearing', [CommunicationInboxGatewayController::class, 'defaultDisappearing']);
                Route::post('/inboxes/{inbox}/app-state/sync', [CommunicationInboxGatewayController::class, 'syncState']);
                Route::post('/inboxes/{inbox}/app-state/mark-clean', [CommunicationInboxGatewayController::class, 'markStateClean']);
                Route::get('/inboxes/{inbox}/blocklist', [CommunicationInboxGatewayController::class, 'blocklist']);
                Route::put('/inboxes/{inbox}/blocklist', [CommunicationInboxGatewayController::class, 'updateBlocklist']);
                Route::get('/inboxes/{inbox}/privacy', [CommunicationInboxGatewayController::class, 'privacy']);
                Route::put('/inboxes/{inbox}/privacy', [CommunicationInboxGatewayController::class, 'updatePrivacy']);
                Route::post('/inboxes/{inbox}/contacts/check', [CommunicationInboxGatewayController::class, 'checkUsers']);
                Route::post('/inboxes/{inbox}/contacts/info', [CommunicationInboxGatewayController::class, 'userInfo']);
                Route::post('/inboxes/{inbox}/contacts/business-profiles', [CommunicationInboxGatewayController::class, 'businessProfiles']);
                Route::post('/inboxes/{inbox}/contacts/profile-picture', [CommunicationInboxGatewayController::class, 'profilePicture']);
                Route::post('/inboxes/{inbox}/contacts/qr-link', [CommunicationInboxGatewayController::class, 'contactQrLink']);
                Route::post('/inboxes/{inbox}/contacts/qr-resolve', [CommunicationInboxGatewayController::class, 'resolveContactQr']);
                Route::post('/inboxes/{inbox}/contacts/business-link-resolve', [CommunicationInboxGatewayController::class, 'resolveBusinessLink']);
                Route::patch('/settings', [CommunicationInboxController::class, 'updateOfficeSettings']);

                Route::get('/automation-policies', [CommunicationAutomationController::class, 'index']);
                Route::put('/automation-policies', [CommunicationAutomationController::class, 'upsert']);
                Route::get('/clients/{client}/automation-recipients', [CommunicationAutomationController::class, 'recipients']);
                Route::put('/clients/{client}/automation-recipients', [CommunicationAutomationController::class, 'updateRecipients']);

                Route::get('/contacts', [CommunicationContactController::class, 'index']);
                Route::post('/contacts', [CommunicationContactController::class, 'store']);
                Route::get('/contacts/{contact}', [CommunicationContactController::class, 'show']);
                Route::patch('/contacts/{contact}', [CommunicationContactController::class, 'update']);
                Route::post('/contacts/{contact}/identities', [CommunicationContactController::class, 'addIdentity']);
                Route::post('/identities/{identity}/links', [CommunicationContactController::class, 'linkIdentity']);
                Route::delete('/identities/{identity}/links/{link}', [CommunicationContactController::class, 'unlinkIdentity']);

                Route::get('/conversations', [CommunicationConversationController::class, 'index']);
                Route::get('/conversations/{conversation}', [CommunicationConversationController::class, 'show']);
                Route::patch('/conversations/{conversation}', [CommunicationConversationController::class, 'update']);
                Route::post('/conversations/{conversation}/messages', [CommunicationConversationController::class, 'send'])
                    ->middleware('throttle:120,1');
                Route::put('/conversations/{conversation}/messages/{message}/edit', [CommunicationConversationGatewayController::class, 'edit']);
                Route::delete('/conversations/{conversation}/messages/{message}', [CommunicationConversationGatewayController::class, 'revoke']);
                Route::put('/conversations/{conversation}/messages/{message}/reaction', [CommunicationConversationGatewayController::class, 'react']);
                Route::post('/conversations/{conversation}/messages/{message}/poll-votes', [CommunicationConversationGatewayController::class, 'votePoll']);
                Route::post('/conversations/{conversation}/messages/{message}/receipts', [CommunicationConversationGatewayController::class, 'receipt']);
                Route::post('/conversations/{conversation}/messages/{message}/history', [CommunicationConversationGatewayController::class, 'history']);
                Route::post('/conversations/{conversation}/messages/{message}/recovery', [CommunicationConversationGatewayController::class, 'recovery']);
                Route::post('/conversations/{conversation}/presence/subscribe', [CommunicationConversationGatewayController::class, 'subscribePresence']);
                Route::put('/conversations/{conversation}/presence', [CommunicationConversationGatewayController::class, 'chatPresence']);
                Route::put('/conversations/{conversation}/disappearing', [CommunicationConversationGatewayController::class, 'disappearing']);
                Route::put('/conversations/{conversation}/state', [CommunicationConversationGatewayController::class, 'state']);
                Route::put('/conversations/{conversation}/labels/{label}', [CommunicationConversationController::class, 'addLabel']);
                Route::delete('/conversations/{conversation}/labels/{label}', [CommunicationConversationController::class, 'removeLabel']);

                Route::get('/labels', [CommunicationCatalogController::class, 'labels']);
                Route::get('/outbound-capabilities', [CommunicationCatalogController::class, 'outboundCapabilities']);
                Route::post('/labels', [CommunicationCatalogController::class, 'storeLabel']);
                Route::delete('/labels/{label}', [CommunicationCatalogController::class, 'deleteLabel']);
                Route::get('/canned-responses', [CommunicationCatalogController::class, 'cannedResponses']);
                Route::post('/canned-responses', [CommunicationCatalogController::class, 'storeCannedResponse']);
                Route::put('/canned-responses/{canned}', [CommunicationCatalogController::class, 'updateCannedResponse']);
                Route::post('/canned-responses/{canned}/duplicate', [CommunicationCatalogController::class, 'duplicateCannedResponse']);
                Route::post('/canned-responses/{canned}/deactivate', [CommunicationCatalogController::class, 'deactivateCannedResponse']);
                Route::post('/canned-responses/{canned}/render', [CommunicationCatalogController::class, 'renderCannedResponse']);
                Route::delete('/canned-responses/{canned}', [CommunicationCatalogController::class, 'deleteCannedResponse']);

                Route::get('/flows', [CommunicationFlowController::class, 'index']);
                Route::post('/flows', [CommunicationFlowController::class, 'store']);
                Route::get('/flows/{flow}', [CommunicationFlowController::class, 'show']);
                Route::patch('/flows/{flow}', [CommunicationFlowController::class, 'update']);
                Route::delete('/flows/{flow}', [CommunicationFlowController::class, 'destroy']);
                Route::get('/flows/{flow}/draft', [CommunicationFlowController::class, 'showDraft']);
                Route::put('/flows/{flow}/draft', [CommunicationFlowController::class, 'updateDraft']);
                Route::post('/flows/{flow}/validate', [CommunicationFlowController::class, 'validateGraph']);
                Route::post('/flows/{flow}/dry-run', [CommunicationFlowController::class, 'dryRun']);
                Route::post('/flows/{flow}/preview', [CommunicationFlowController::class, 'previewGraph']);
                Route::post('/flows/{flow}/publish', [CommunicationFlowController::class, 'publish']);
                Route::post('/flows/{flow}/clone', [CommunicationFlowController::class, 'cloneFlow']);
                Route::post('/flows/{flow}/versions/{version}/clone', [CommunicationFlowController::class, 'cloneVersion']);
                Route::get('/flows/{flow}/bindings', [CommunicationFlowController::class, 'indexBindings']);
                Route::post('/flows/{flow}/bindings', [CommunicationFlowController::class, 'storeBinding']);
                Route::patch('/flow-bindings/{binding}', [CommunicationFlowController::class, 'updateBinding']);
                Route::post('/flow-bindings/{binding}/enable', [CommunicationFlowController::class, 'enableBinding']);
                Route::post('/flow-bindings/{binding}/disable', [CommunicationFlowController::class, 'disableBinding']);
                Route::delete('/flow-bindings/{binding}', [CommunicationFlowController::class, 'destroyBinding']);
                Route::get('/flow-runs', [CommunicationFlowRunController::class, 'index']);
                Route::get('/flow-runs/{run}', [CommunicationFlowRunController::class, 'show']);
                Route::post('/flow-runs/{run}/pause', [CommunicationFlowRunController::class, 'pause']);
                Route::post('/flow-runs/{run}/resume', [CommunicationFlowRunController::class, 'resume']);
                Route::post('/flow-runs/{run}/handoff', [CommunicationFlowRunController::class, 'handoff']);
                Route::post('/flow-runs/{run}/stop', [CommunicationFlowRunController::class, 'stop']);
                Route::post('/flow-runs/{run}/restart', [CommunicationFlowRunController::class, 'restart']);

                Route::get('/events', [CommunicationDataController::class, 'sync']);
                Route::get('/attachments/{attachment}/download', [CommunicationDataController::class, 'downloadAttachment']);
                Route::get('/attachments/{attachment}/preview', [CommunicationDataController::class, 'previewAttachment']);
                Route::get('/contacts/{contact}/export', [CommunicationDataController::class, 'exportContact']);
                Route::delete('/contacts/{contact}/personal-data', [CommunicationDataController::class, 'purgeContact']);
            });

            // Tenant SERPRO namespace canônico (/api/v1/serpro/*) — office_id só via CurrentOffice
            Route::get('/serpro/authorization', [SerproTenantController::class, 'authorization']);
            Route::get('/serpro/readiness', [SerproTenantController::class, 'readiness']);
            Route::get('/serpro/health', [SerproTenantController::class, 'health']);
            Route::get('/serpro/usage', [SerproTenantController::class, 'usageSummary']);
            Route::get('/serpro/usage/entries', [SerproTenantController::class, 'usageEntries']);

            // Onboarding Integra: Autor, Termo, procurações (sem XML/PFX/tokens na resposta)
            Route::get('/office/serpro-authorization', [OfficeSerproAuthorizationController::class, 'show']);
            Route::post('/office/serpro-authorization/author', [OfficeSerproAuthorizationController::class, 'configureAuthor']);
            Route::post('/office/serpro-authorization/termo/draft', [OfficeSerproAuthorizationController::class, 'generateTermoDraft']);
            Route::get('/office/serpro-authorization/termo/draft', [OfficeSerproAuthorizationController::class, 'downloadTermoDraft']);
            Route::post('/office/serpro-authorization/termo', [OfficeSerproAuthorizationController::class, 'uploadTermo']);
            Route::post('/office/serpro-authorization/termo/sign-managed-a1', [OfficeSerproAuthorizationController::class, 'signTermoManagedA1']);
            Route::post('/office/serpro-authorization/author-a1', [OfficeSerproAuthorizationController::class, 'storeAuthorA1']);
            Route::post('/office/serpro-authorization/refresh-token', [OfficeSerproAuthorizationController::class, 'refreshToken']);
            Route::get('/office/serpro-authorization/proxy-powers', [OfficeSerproAuthorizationController::class, 'listProxyPowers']);
            Route::post('/office/serpro-authorization/proxy-powers', [OfficeSerproAuthorizationController::class, 'importProxyPower']);
            Route::post('/office/serpro-authorization/proxy-powers/sync', [OfficeSerproAuthorizationController::class, 'syncProxyPowers']);
            Route::post('/office/serpro-authorization/eligibility', [OfficeSerproAuthorizationController::class, 'eligibility']);
            Route::get('/office/serpro-authorization/health', [OfficeSerproAuthorizationController::class, 'platformHealth']);

            // Consumo/franquia SERPRO do tenant (sem orçamento global nem outros offices)
            Route::get('/office/serpro-usage', [OfficeSerproUsageController::class, 'summary']);
            Route::get('/office/serpro-usage/entries', [OfficeSerproUsageController::class, 'entries']);

            // Canário DTE — confirmação Office ADMIN + resultado fiscal (membership)
            Route::get('/serpro/dte-canary/pending', [DteCanaryTenantController::class, 'pending']);
            Route::post('/serpro/dte-canary/{serproDteCanaryRequest}/confirm', [DteCanaryTenantController::class, 'confirmParticipation'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
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

            // Read model de carteira por módulo (overview + clients; office_id só via membership)
            Route::get('/fiscal/modules/{module}/overview', [FiscalModulePortfolioController::class, 'overview']);
            Route::get('/fiscal/modules/{module}/clients', [FiscalModulePortfolioController::class, 'clients']);

            // DCTFWeb / MIT (evidências versionadas; transmissão/encerramento atrás de flags mutantes OFF)
            Route::get('/fiscal/dctfweb/declarations', [DctfwebController::class, 'indexDeclarations']);
            Route::get('/fiscal/dctfweb/declarations/{declaration}', [DctfwebController::class, 'showDeclaration']);
            Route::post('/fiscal/dctfweb/events', [DctfwebController::class, 'ingestEvent']);
            Route::post('/fiscal/dctfweb/consult', [DctfwebController::class, 'enqueueConsult']);
            Route::post('/fiscal/dctfweb/transmit', [DctfwebController::class, 'transmit'])
                ->middleware('throttle:10,1');
            Route::get('/fiscal/mit/apuracoes', [MitController::class, 'index']);
            Route::get('/fiscal/mit/apuracoes/{apuracao}', [MitController::class, 'show']);
            Route::get('/fiscal/mit/lista-apuracoes', [MitController::class, 'indexListaApuracoes']);
            Route::post('/fiscal/mit/consult', [MitController::class, 'enqueueConsult']);
            Route::post('/fiscal/mit/lista-apuracoes', [MitController::class, 'enqueueListaApuracoes']);
            Route::post('/fiscal/mit/encerrar', [MitController::class, 'encerrar'])
                ->middleware('throttle:10,1');

            // Parcelamentos SN/MEI (modalidades catalogadas; mutantes OFF)
            Route::get('/fiscal/installments/modalities', [TaxInstallmentController::class, 'modalities']);
            Route::get('/fiscal/installments/orders', [TaxInstallmentController::class, 'orders']);
            Route::get('/fiscal/installments/orders/{order}', [TaxInstallmentController::class, 'showOrder']);
            Route::get('/fiscal/installments/parcels', [TaxInstallmentController::class, 'parcels']);
            Route::get('/fiscal/installments/guides', [TaxInstallmentController::class, 'guides']);
            Route::post('/fiscal/installments/monitor', [TaxInstallmentController::class, 'monitor']);
            Route::post('/fiscal/installments/runs', [TaxInstallmentController::class, 'enqueue']);

            // Operações fiscais mutantes (OFF por default; senha recente + confirmação + idempotência)
            Route::post('/auth/confirm-totp', [FiscalMutationController::class, 'confirmTotp'])
                ->middleware('throttle:10,1'); // legado: redireciona mentalmente a confirm-password
            Route::post('/fiscal/mutations/preflight', [FiscalMutationController::class, 'preflight'])
                ->middleware('throttle:30,1');
            Route::post('/fiscal/mutations', [FiscalMutationController::class, 'execute'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::get('/fiscal/mutations/{mutation}', [FiscalMutationController::class, 'show']);
            Route::post('/fiscal/mutations/{mutation}/reconcile', [FiscalMutationController::class, 'reconcile'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);

            // Situação Fiscal (SITFIS) — snapshot com idade; refresh respeita TTL
            Route::get('/fiscal/sitfis', [SitfisSituationController::class, 'show']);
            Route::post('/fiscal/sitfis/refresh', [SitfisSituationController::class, 'refresh']);
            Route::get('/fiscal/sitfis/clients/{client}/history', [SitfisSituationController::class, 'history']);

            // Explorador de consultas manuais (somente leitura; GET local; POST confirmado)
            Route::get('/fiscal/manual-consults', [ManualConsultController::class, 'index']);
            Route::post('/fiscal/manual-consults', [ManualConsultController::class, 'store'])
                ->middleware('throttle:30,1');

            // Cadastro/Vínculos (PNR Contador) — listagem + detalhe + refresh explícito
            Route::get('/fiscal/registrations', [RegistrationLinkController::class, 'index']);
            Route::get('/fiscal/clients/{clientId}/registrations', [RegistrationLinkController::class, 'showForClient']);
            Route::post('/fiscal/clients/{clientId}/registrations/refresh', [RegistrationLinkController::class, 'refresh'])
                ->middleware('throttle:20,1');

            // PNR Contador — leituras de renúncia explicitamente acionadas pelo usuário.
            Route::get('/fiscal/clients/{clientId}/pnr-renunciations', [PnrRenunciationController::class, 'index']);
            Route::post('/fiscal/clients/{clientId}/pnr-renunciations/history', [PnrRenunciationController::class, 'history'])
                ->middleware('throttle:10,1');
            Route::post('/fiscal/clients/{clientId}/pnr-renunciations/status', [PnrRenunciationController::class, 'status'])
                ->middleware('throttle:10,1');
            Route::post('/fiscal/clients/{clientId}/pnr-renunciations/receipt', [PnrRenunciationController::class, 'receipt'])
                ->middleware('throttle:10,1');

            // Processos fiscais (e-Processo)
            Route::get('/fiscal/tax-processes', [TaxProcessController::class, 'index']);
            Route::get('/fiscal/clients/{clientId}/tax-processes', [TaxProcessController::class, 'showForClient']);
            Route::post('/fiscal/clients/{clientId}/tax-processes/refresh', [TaxProcessController::class, 'refresh'])
                ->middleware('throttle:20,1');
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
                ->middleware('throttle:20,1');
            Route::get('/fiscal/mailbox/messages/{message}/detail-preview', [MailboxMonitoringController::class, 'detailPreview']);
            Route::post('/fiscal/mailbox/messages/{message}/detail', [MailboxMonitoringController::class, 'detail'])
                ->middleware('throttle:20,1');

            // Central de declarações (catálogo versionado, projeções, recibos — sem guias)
            Route::get('/fiscal/declarations/catalog', [DeclarationHubController::class, 'catalog']);
            Route::get('/fiscal/declarations/summary', [DeclarationHubController::class, 'summary']);
            Route::get('/fiscal/declarations', [DeclarationHubController::class, 'index']);
            Route::post('/fiscal/declarations/operations/{action}/read', [DeclarationOperationController::class, 'read'])
                ->middleware('throttle:30,1');
            Route::post('/fiscal/declarations/operations/{action}/preflight', [DeclarationOperationController::class, 'preflight'])
                ->middleware('throttle:30,1');
            Route::post('/fiscal/declarations/operations/{action}/execute', [DeclarationOperationController::class, 'execute'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::get('/fiscal/declarations/operations/mutations/{mutation}', [DeclarationOperationController::class, 'show'])
                ->whereNumber('mutation');
            Route::post('/fiscal/declarations/operations/mutations/{mutation}/reconcile', [DeclarationOperationController::class, 'reconcile'])
                ->whereNumber('mutation')
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/fiscal/declarations/project', [DeclarationHubController::class, 'project']);
            Route::post('/fiscal/declarations/calendar', [DeclarationHubController::class, 'publishCalendar']);
            Route::get('/fiscal/declarations/{projection}', [DeclarationHubController::class, 'show']);
            Route::post('/fiscal/declarations/{projection}/evidences', [DeclarationHubController::class, 'attachEvidence']);
            Route::get('/fiscal/declarations/{projection}/evidences/{evidence}', [DeclarationHubController::class, 'showEvidence']);

            // Central de guias (mutações OFF por default — FeatureFlags guias)
            Route::get('/fiscal/guides', [TaxGuideController::class, 'index']);
            Route::post('/fiscal/guides/preflight', [TaxGuideController::class, 'preflight']);
            Route::post('/fiscal/guides/challenge', [TaxGuideController::class, 'challenge'])
                ->middleware('throttle:10,1');
            Route::post('/fiscal/guides', [TaxGuideController::class, 'store'])
                ->middleware('throttle:20,1');
            Route::get('/fiscal/guides/downloads/{token}', [TaxGuideController::class, 'download']);
            Route::get('/fiscal/guides/{guide}', [TaxGuideController::class, 'show']);
            Route::post('/fiscal/guides/{guide}/download-token', [TaxGuideController::class, 'issueDownloadToken']);
            Route::post('/fiscal/guides/{guide}/payment-confirmations', [TaxGuideController::class, 'confirmPayment']);
            Route::post('/fiscal/guides/{guide}/reconcile', [TaxGuideController::class, 'reconcile']);

            // SICALC 5.2 — metadados de preenchimento de DARF (somente leitura).
            Route::get('/fiscal/guides/revenue-support/clients/{client}/history', [SicalcRevenueSupportController::class, 'history']);
            Route::post('/fiscal/guides/revenue-support/clients/{client}/consult', [SicalcRevenueSupportController::class, 'consult']);
            Route::get('/fiscal/guides/payment-count/clients/{client}/history', [PagtowebPaymentCountController::class, 'history']);
            Route::post('/fiscal/guides/payment-count/clients/{client}/consult', [PagtowebPaymentCountController::class, 'consult']);
            Route::get('/fiscal/guides/payments/clients/{client}/history', [PagtowebPaymentListController::class, 'history']);
            Route::post('/fiscal/guides/payments/clients/{client}/consult', [PagtowebPaymentListController::class, 'consult']);
            Route::get('/fiscal/guides/receipts/clients/{client}/history', [PagtowebArrecadacaoReceiptController::class, 'history']);
            Route::post('/fiscal/guides/receipts/clients/{client}/request', [PagtowebArrecadacaoReceiptController::class, 'request'])->middleware('throttle:10,1');
            Route::get('/fiscal/guides/receipts/clients/{client}/{receipt}/download', [PagtowebArrecadacaoReceiptController::class, 'download']);

            // Simples Nacional / MEI (tenant-scoped; mutações bloqueadas no piloto)
            Route::get('/fiscal/simples-mei/catalog', [SimplesMeiController::class, 'catalog']);
            Route::get('/fiscal/simples-mei/clients/{client}/regimes', [SimplesMeiController::class, 'regimes']);
            Route::get('/fiscal/simples-mei/clients/{client}/competences', [SimplesMeiController::class, 'competences']);
            Route::get('/fiscal/simples-mei/clients/{client}/snapshots', [SimplesMeiController::class, 'snapshots']);
            Route::get('/fiscal/simples-mei/clients/{client}/regime-calendar', [SimplesMeiController::class, 'regimeCalendar']);
            Route::get('/fiscal/simples-mei/clients/{client}/regime-options', [SimplesMeiController::class, 'regimeOptions']);
            Route::get('/fiscal/simples-mei/clients/{client}/regime-resolutions', [SimplesMeiController::class, 'regimeResolutions']);
            Route::post('/fiscal/simples-mei/consult', [SimplesMeiController::class, 'consult']);
            Route::post('/fiscal/simples-mei/regime-calendar/consult', [SimplesMeiController::class, 'consultRegimeCalendar']);
            Route::post('/fiscal/simples-mei/regime-option/consult', [SimplesMeiController::class, 'consultRegimeOption']);
            Route::post('/fiscal/simples-mei/regime-resolution/consult', [SimplesMeiController::class, 'consultRegimeResolution']);
            Route::post('/fiscal/simples-mei/das', [SimplesMeiController::class, 'generateDas']);
            Route::post('/fiscal/simples-mei/transmit', [SimplesMeiController::class, 'transmit']);

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
                ->middleware('throttle:30,1');
            Route::post('/fiscal/simples-mei/pgmei/das', [MeiDasController::class, 'store'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
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
            Route::post('/fiscal/simples-mei/ccmei/clients/{client}/issued-certificates', [CcmeiMonitoringController::class, 'issueCertificate'])->middleware('throttle:10,1');
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
            Route::post('/fiscal/fgts/digital/sync', [FgtsDigitalController::class, 'sync'])->middleware('throttle:20,1');
            Route::post('/fiscal/fgts/digital/sync-now', [FgtsDigitalController::class, 'syncNow'])->middleware('throttle:10,1');
            Route::post('/fiscal/fgts/digital/preview', [FgtsDigitalController::class, 'preview'])->middleware('throttle:10,1');
            Route::post('/fiscal/fgts/digital/previews/{run}/emit', [FgtsDigitalController::class, 'emit'])->whereNumber('run')->middleware('throttle:10,1');
            Route::post('/fiscal/fgts/digital/sessions/import', [FgtsDigitalController::class, 'importSession'])->middleware('throttle:10,1');
            Route::post('/fiscal/fgts/digital/representations', [FgtsDigitalController::class, 'storeRepresentation'])->middleware('throttle:10,1');

            Route::get('/clients', [ClientController::class, 'index']);
            Route::get('/cnpj/{cnpj}/lookup', CnpjLookupController::class)->middleware('throttle:30,1');
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
                ->middleware('throttle:20,1');

            Route::post('/clients/{client}/establishments', [EstablishmentController::class, 'store']);
            Route::patch('/establishments/{establishment}', [EstablishmentController::class, 'update']);

            Route::get('/clients/{client}/contacts', [ClientContactController::class, 'index']);
            Route::post('/clients/{client}/contacts', [ClientContactController::class, 'store']);
            Route::patch('/clients/{client}/contacts/{contact}', [ClientContactController::class, 'update']);
            Route::delete('/clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroy']);

            Route::get('/clients/{client}/credential', [ClientCredentialController::class, 'show']);
            Route::post('/clients/{client}/credential', [ClientCredentialController::class, 'store']);

            // Identidade fiscal e A1 do escritório (sem rota de recuperação/download)
            Route::get('/office/fiscal-identity', [OfficeFiscalCredentialController::class, 'showIdentity']);
            Route::post('/office/fiscal-identity', [OfficeFiscalCredentialController::class, 'storeIdentity']);
            Route::post('/office/fiscal-identity/credential', [OfficeFiscalCredentialController::class, 'storeCredential'])
                ->middleware(EnsureRecentPasswordConfirmation::class);
            Route::post('/office/fiscal-identity/credentials/{credential}/revoke', [OfficeFiscalCredentialController::class, 'revokeCredential'])
                ->middleware(EnsureRecentPasswordConfirmation::class);

            // Configuração unificada /settings: perfil, consentimento, A1 canônico (sem download)
            Route::get('/office/settings', [OfficeSettingsController::class, 'show']);
            Route::patch('/office/settings/profile', [OfficeSettingsController::class, 'updateProfile']);
            Route::get('/office/settings/consent', [OfficeSettingsController::class, 'showConsent']);
            Route::post('/office/settings/consent', [OfficeSettingsController::class, 'grantConsent']);
            Route::post('/office/settings/consent/revoke', [OfficeSettingsController::class, 'revokeConsent']);
            // A1 canônico: só ADMIN (policy) + senha do PFX. Sem reconfirmação de senha de
            // login no formulário — o material sensível é a senha do certificado, não a da conta.
            Route::get('/office/settings/credential', [OfficeSettingsController::class, 'showCredential']);
            Route::post('/office/settings/credential', [OfficeSettingsController::class, 'storeCredential']);
            Route::post('/office/settings/credential/replace', [OfficeSettingsController::class, 'replaceCredential']);
            Route::post('/office/settings/credential/remove', [OfficeSettingsController::class, 'removeCredential']);
            Route::post('/office/settings/refresh-integration', [OfficeSettingsController::class, 'refreshIntegration']);
            Route::get('/office/settings/monitor-schedules', [OfficeSettingsController::class, 'listMonitorSchedules']);
            Route::put('/office/settings/monitor-schedules/{monitorKey}', [OfficeSettingsController::class, 'updateMonitorSchedule']);
            Route::get('/office/settings/onboarding-status', [OfficeSettingsController::class, 'onboardingStatus']);

            // Equipe do escritório — exige membership ADMIN real (checado no serviço)
            Route::get('/office/members', [OfficeMemberController::class, 'index']);
            Route::post('/office/members', [OfficeMemberController::class, 'store'])
                ->middleware(['throttle:30,1', EnsureRecentPasswordConfirmation::class]);
            Route::patch('/office/members/{membership}', [OfficeMemberController::class, 'update'])
                ->middleware(EnsureRecentPasswordConfirmation::class);
            Route::patch('/office/members/{membership}/recipient', [OfficeMemberController::class, 'updateRecipient'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/office/members/{membership}/deactivate', [OfficeMemberController::class, 'deactivate'])
                ->middleware(EnsureRecentPasswordConfirmation::class);
            Route::post('/office/members/{membership}/reactivate', [OfficeMemberController::class, 'reactivate'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);
            Route::post('/office/members/{membership}/activation/regenerate', [OfficeMemberController::class, 'regenerateActivation'])
                ->middleware(['throttle:20,1', EnsureRecentPasswordConfirmation::class]);

            // Onboarding autXML + cursor central (sem reset de NSU)
            Route::get('/office/autxml', [OfficeAutXmlController::class, 'overview']);
            Route::get('/office/autxml/cursor', [OfficeAutXmlController::class, 'cursor']);
            Route::post('/office/autxml/enrollments', [OfficeAutXmlController::class, 'enroll']);
            Route::post('/office/autxml/enrollments/{enrollment}/confirm', [OfficeAutXmlController::class, 'confirm']);
            Route::post('/office/autxml/enrollments/{enrollment}/inactivate', [OfficeAutXmlController::class, 'inactivate']);

            // Tokens de integração CT-e (EMITTER_PUSH) — ADMIN+2FA emite/revoga; sem recuperação
            Route::get('/office/integration-tokens', [CteEmitterPushController::class, 'listTokens']);
            Route::post('/office/integration-tokens', [CteEmitterPushController::class, 'issueToken'])
                ->middleware('throttle:'.(int) config('sefaz.cte_emitter_push.admin_token_rate_limit_per_minute', 10).',1');
            Route::post('/office/integration-tokens/{token}/revoke', [CteEmitterPushController::class, 'revokeToken'])
                ->middleware('throttle:'.(int) config('sefaz.cte_emitter_push.admin_token_rate_limit_per_minute', 10).',1');

            // Operação CT-e: onboarding, dois streams, cobertura e pendências sanitizadas
            Route::get('/cte/onboarding', [CteOperationsController::class, 'onboarding']);
            Route::get('/cte/health', [CteOperationsController::class, 'health']);
            Route::get('/cte/coverage', [CteOperationsController::class, 'coverage']);
            Route::get('/cte/pending', [CteOperationsController::class, 'pending']);
            Route::post('/cte/repairs', [CteOperationsController::class, 'repairKnownNsu']);

            // Catálogo unificado Documentos (canônico)
            Route::get('/documents', [NoteController::class, 'index']);
            Route::get('/documents/by-client', [NoteController::class, 'byClient']);
            Route::get('/documents/insights', [NoteController::class, 'insights']);
            Route::post('/documents/import', [DocumentImportController::class, 'store']);
            Route::get('/documents/import-batches', [DocumentImportBatchController::class, 'index']);
            Route::post('/documents/import-batches', [DocumentImportBatchController::class, 'store']);
            Route::get('/documents/import-batches/{batch}', [DocumentImportBatchController::class, 'show']);
            Route::get('/documents/import-batches/{batch}/items', [DocumentImportBatchController::class, 'items']);
            Route::post('/documents/import-batches/{batch}/items/{item}/retry', [DocumentImportBatchController::class, 'retryItem']);
            Route::get('/documents/import-batches/{batch}/export.csv', [DocumentImportBatchController::class, 'exportCsv']);
            Route::get('/documents/{accessKey}', [NoteController::class, 'show']);
            Route::get('/documents/{accessKey}/xml', [NoteController::class, 'downloadXml']);
            Route::post('/documents/{accessKey}/unlock-xml', [NoteController::class, 'unlockXml']);
            Route::post('/documents/{accessKey}/manifestations', [NoteController::class, 'manifest']);

            // Alias compatível (legado "notes")
            Route::get('/notes', [NoteController::class, 'index']);
            Route::get('/notes/by-client', [NoteController::class, 'byClient']);
            Route::get('/notes/insights', [NoteController::class, 'insights']);
            Route::get('/notes/{accessKey}', [NoteController::class, 'show']);
            Route::get('/notes/{accessKey}/xml', [NoteController::class, 'downloadXml']);

            Route::get('/sync-runs', [SyncController::class, 'history']);
            Route::post('/sync-runs', [SyncController::class, 'trigger']);

            Route::get('/exports', [ExportController::class, 'index']);
            Route::post('/exports', [ExportController::class, 'store']);
            Route::get('/exports/{export}/download', [ExportController::class, 'download']);

            // Filtros salvos de lista (personal | office; office_id só via CurrentOffice)
            Route::get('/list-filters', [SavedListFilterController::class, 'index']);
            Route::post('/list-filters', [SavedListFilterController::class, 'store']);
            Route::patch('/list-filters/{listFilter}', [SavedListFilterController::class, 'update']);
            Route::delete('/list-filters/{listFilter}', [SavedListFilterController::class, 'destroy']);

            Route::get('/operations/summary', OperationsSummaryController::class);
            Route::get('/operations/inbox', OperationsInboxController::class);

            // Assistente de produto (OpenAI via API; fail-closed)
            Route::prefix('assistant')->middleware('throttle:60,1')->group(function (): void {
                Route::get('/conversations', [AssistantConversationController::class, 'index']);
                Route::post('/conversations', [AssistantConversationController::class, 'store']);
                Route::get('/conversations/{conversation}/messages', [AssistantConversationController::class, 'messages']);
                Route::post('/conversations/{conversation}/chat', [AssistantChatController::class, 'chat'])
                    ->middleware('throttle:30,1');
                Route::post('/conversations/{conversation}/approve-tool', [AssistantChatController::class, 'approve'])
                    ->middleware(['throttle:20,1', EnsureWorkRealMembership::class]);
                Route::post('/conversations/{conversation}/deny-tool', [AssistantChatController::class, 'deny'])
                    ->middleware(['throttle:20,1', EnsureWorkRealMembership::class]);
            });

            // ── Work: processos operacionais (plano de dados; sem SERPRO/ADN/SEFAZ) ──
            // Leitura: membership ou platform_privileged. Mutação/export: membership real.
            // @see config/work_route_matrix.php
            Route::prefix('work')->group(function (): void {
                Route::get('/departments', [WorkDepartmentController::class, 'index']);
                Route::get('/template-catalog', [ProcessTemplateCatalogController::class, 'index']);
                Route::get('/templates', [ProcessTemplateController::class, 'index']);
                Route::get('/templates/{template}', [ProcessTemplateController::class, 'show']);
                Route::get('/templates/{template}/recurrence', [ProcessTemplateController::class, 'showRecurrence']);
                Route::get('/templates/{template}/generation-batches', [ProcessTemplateController::class, 'generationBatches']);
                Route::get('/generation-batches/{batch}', [ProcessGenerationController::class, 'show']);
                Route::get('/queue', [OperationalTaskController::class, 'queue']);
                Route::get('/process-groups', [OperationalProcessGroupController::class, 'index']);
                Route::get('/processes', [OperationalProcessController::class, 'index']);
                Route::get('/processes/{process}', [OperationalProcessController::class, 'show']);
                Route::get('/processes/{process}/timeline', [OperationalProcessController::class, 'timeline']);
                Route::get('/tasks/{task}', [OperationalTaskController::class, 'show']);
                Route::get('/tasks/{task}/evidences/{evidence}/download', [OperationalTaskController::class, 'downloadEvidence']);
                Route::get('/kpis', [OperationalDashboardController::class, 'kpis']);
                Route::get('/calendar', [OperationalDashboardController::class, 'calendar']);
                Route::get('/calendar/day', [OperationalDashboardController::class, 'calendarDay']);
                Route::get('/exports/{export}', [OperationalDashboardController::class, 'showExport']);
                Route::get('/exports/{export}/download', [OperationalDashboardController::class, 'downloadExport']);

                Route::middleware([EnsureWorkRealMembership::class])->group(function (): void {
                    Route::post('/departments', [WorkDepartmentController::class, 'store']);
                    Route::patch('/departments/{department}', [WorkDepartmentController::class, 'update']);
                    Route::post('/departments/{department}/assign-membership', [WorkDepartmentController::class, 'assignMembership']);

                    Route::post('/templates', [ProcessTemplateController::class, 'store']);
                    Route::post('/template-catalog/{catalogKey}/install', [ProcessTemplateCatalogController::class, 'install']);
                    Route::patch('/templates/{template}', [ProcessTemplateController::class, 'update']);
                    Route::patch('/templates/{template}/recurrence', [ProcessTemplateController::class, 'updateRecurrence']);
                    Route::post('/templates/{template}/preview', [ProcessGenerationController::class, 'preview']);
                    Route::post('/generation-batches/{batch}/confirm', [ProcessGenerationController::class, 'confirm']);
                    Route::post('/generation-batches/{batch}/retry', [ProcessGenerationController::class, 'retry']);

                    Route::post('/processes', [OperationalProcessController::class, 'store']);
                    Route::patch('/processes/{process}', [OperationalProcessController::class, 'update']);
                    Route::post('/processes/bulk', [OperationalProcessController::class, 'bulk']);
                    Route::post('/processes/{process}/archive', [OperationalProcessController::class, 'archive']);
                    Route::post('/processes/{process}/comments', [OperationalProcessController::class, 'comment']);

                    Route::post('/processes/{process}/tasks', [OperationalTaskController::class, 'storeOnProcess']);
                    Route::post('/processes/{process}/tasks/reorder', [OperationalTaskController::class, 'reorder']);
                    Route::patch('/tasks/{task}/structure', [OperationalTaskController::class, 'updateStructure']);
                    Route::post('/tasks/{task}/start', [OperationalTaskController::class, 'start']);
                    Route::post('/tasks/{task}/block', [OperationalTaskController::class, 'block']);
                    Route::post('/tasks/{task}/resume', [OperationalTaskController::class, 'resume']);
                    Route::post('/tasks/{task}/complete', [OperationalTaskController::class, 'complete']);
                    Route::post('/tasks/{task}/dispense', [OperationalTaskController::class, 'dispense']);
                    Route::post('/tasks/{task}/reopen', [OperationalTaskController::class, 'reopen']);
                    Route::post('/tasks/{task}/claim', [OperationalTaskController::class, 'claim']);
                    Route::post('/tasks/{task}/assign', [OperationalTaskController::class, 'assign']);
                    Route::post('/tasks/{task}/comments', [OperationalTaskController::class, 'comment']);
                    Route::post('/tasks/{task}/evidences', [OperationalTaskController::class, 'uploadEvidence']);
                    Route::delete('/tasks/{task}/evidences/{evidence}', [OperationalTaskController::class, 'removeEvidence']);
                    Route::post('/tasks/bulk', [OperationalTaskController::class, 'bulk']);

                    Route::post('/exports', [OperationalDashboardController::class, 'createExport']);
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
            Route::get('/outbound/svrs-nfce/recoveries/{recovery}', [SvrsNfceRecoveryController::class, 'attempts']);
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
