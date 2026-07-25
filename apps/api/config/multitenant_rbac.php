<?php

/**
 * Configuração e allowlists da migração de RBAC multi-tenant canônico.
 *
 * @see docs/ops/multitenant-rbac-inventory.md
 * @see openspec/changes/padronizar-autorizacao-multitenant
 * @see tests/Architecture/MultitenantRbacGuardrailsTest
 */
return [
    /**
     * Paths relativos a apps/api/app que ainda podem referenciar papéis legados
     * de autenticação (OfficeRole, PlatformRole, PlatformOwner*, PLATFORM_ADMIN
     * de storage, literais ADMIN|OPERATOR|VIEWER de RBAC) durante expand–cutover.
     * Remover entradas à medida que a família migrar.
     *
     * NÃO confundir com códigos de domínio não-RBAC (OPERATOR_REVIEW, FiscalRole,
     * OWNER_* históricos de protocolo SERPRO, atributos HTML role, etc.) — o
     * detector de arquitetura usa padrões de alta especificidade.
     *
     * @var list<string>
     */
    'legacy_auth_literal_allowlist' => [
        'Console/Commands/BootstrapOfficeCommand.php',
        'Console/Commands/OpsPreflightTenantIsolationCommand.php',
        'Console/Commands/PlatformOwnerConsolidateCommand.php',
        'Console/Commands/PlatformOwnerRecoverCommand.php',
        'Casts/CompatiblePlatformRoleCast.php',
        'Casts/NullableTenantRoleCast.php',
        'Enums/ActivationPurpose.php',
        'Enums/OfficeRole.php',
        'Enums/PlatformRole.php',
        'Enums/TenantPermission.php',
        'Enums/TenantRole.php',
        'Models/TenantPermissionProfile.php',
        'Models/TenantPermissionProfilePermission.php',
        'Http/Controllers/Api/V1/CteEmitterPushController.php',
        'Http/Controllers/Api/V1/CteOperationsController.php',
        'Http/Controllers/Api/V1/DteCanaryTenantController.php',
        'Http/Controllers/Api/V1/Fiscal/DctfwebController.php',
        'Http/Controllers/Api/V1/Fiscal/DctfwebMonitoringController.php',
        'Http/Controllers/Api/V1/Fiscal/DeclarationHubController.php',
        'Http/Controllers/Api/V1/Fiscal/FgtsEsocialController.php',
        'Http/Controllers/Api/V1/Fiscal/FiscalCategoryController.php',
        'Http/Controllers/Api/V1/Fiscal/FiscalMonitoringRunController.php',
        'Http/Controllers/Api/V1/Fiscal/FiscalMutationController.php',
        'Http/Controllers/Api/V1/Fiscal/MitController.php',
        'Http/Controllers/Api/V1/Fiscal/PgdasdMonitoringController.php',
        'Http/Controllers/Api/V1/Fiscal/PgmeiMonitoringController.php',
        'Http/Controllers/Api/V1/Fiscal/RegistrationLinkController.php',
        'Http/Controllers/Api/V1/Fiscal/SimplesMeiController.php',
        'Http/Controllers/Api/V1/Fiscal/SitfisSituationController.php',
        'Http/Controllers/Api/V1/Fiscal/TaxGuideController.php',
        'Http/Controllers/Api/V1/Fiscal/TaxInstallmentController.php',
        'Http/Controllers/Api/V1/Fiscal/TaxProcessController.php',
        'Http/Controllers/Api/V1/Office/OfficeMemberController.php',
        'Http/Controllers/Api/V1/OfficeSerproAuthorizationController.php',
        'Http/Controllers/Api/V1/OutboundCaptureController.php',
        'Http/Controllers/Api/V1/OutboundDeadlineController.php',
        'Http/Controllers/Api/V1/Platform/PlatformOfficeController.php',
        'Http/Controllers/Api/V1/Platform/PlatformOfficeSelectController.php',
        'Http/Controllers/Api/V1/Platform/PlatformOwnerController.php',
        'Http/Controllers/Api/V1/SerproTenantController.php',
        'Http/Controllers/Api/V1/SvrsNfceRecoveryController.php',
        'Http/Requests/Clients/StoreClientRequest.php',
        'Models/OfficeMembership.php',
        'Models/PlatformMembership.php',
        'Models/User.php',
        'Policies/ClientContactPolicy.php',
        'Policies/ClientCredentialPolicy.php',
        'Policies/ClientPolicy.php',
        'Policies/EstablishmentPolicy.php',
        'Policies/OfficeFiscalCredentialPolicy.php',
        'Policies/OfficeSettingsPolicy.php',
        'Policies/OutboundCaptureProfilePolicy.php',
        'Policies/SavedListFilterPolicy.php',
        'Policies/SerproTenantAccessPolicy.php',
        'Policies/Work/Concerns/UsesRealWorkRole.php',
        'Services/Activation/CorrectPendingRecipientService.php',
        'Services/Activation/CreatePendingOfficeService.php',
        'Services/Activation/OfficeTeamService.php',
        'Services/Fiscal/Dctfweb/DctfwebCommunicationService.php',
        'Services/Fiscal/Guides/GuideHighRiskGate.php',
        'Services/Fiscal/Mutations/FiscalMutationPolicy.php',
        'Services/Fiscal/SimplesMei/Pgdasd/PgdasdCommunicationService.php',
        'Services/Fiscal/SimplesMei/Pgmei/PgmeiCommunicationService.php',
        'Services/Integra/Dctfweb/DctfwebMutationGuard.php',
        'Services/Integra/IntegraEligibilityService.php',
        'Services/Operations/Inbox/CredentialBackupItemsCollector.php',
        'Services/Operations/Inbox/CteItemsCollector.php',
        'Services/Operations/Inbox/CursorSyncItemsCollector.php',
        'Services/Operations/Inbox/FiscalItemsCollector.php',
        'Services/Operations/Inbox/InboxItemFactory.php',
        'Services/Operations/Inbox/MailboxItemsCollector.php',
        'Services/Operations/Inbox/OutboundSvrsItemsCollector.php',
        'Services/Operations/Inbox/QuarantineItemsCollector.php',
        'Services/Operations/Inbox/SerproProxyUsageItemsCollector.php',
        'Services/Operations/OperationsInboxBuilder.php',
        'Services/Operations/OperationsSummaryBuilder.php',
        'Services/Outbound/MutatingProbeGateEvaluator.php',
        'Services/Authorization/TenantAuthorization.php',
        'Services/Platform/InitialOnboardingService.php',
        'Services/Platform/MultitenantRbacMigrateService.php',
        'Services/Platform/PlatformOfficeSelectService.php',
        'Services/Platform/PlatformOwnerException.php',
        'Services/Platform/PlatformOwnerService.php',
        'Support/MultitenantRbac/EffectivePermissionsResolver.php',
        'Support/MultitenantRbac/MeIdentityPresenter.php',
        'Support/MultitenantRbac/RoleStorageAdapter.php',
        'Services/Sefaz/FiscalDocumentQuarantineService.php',
        'Services/Serpro/SerproCredentialVersionService.php',
        'Services/Serpro/SerproDteCanaryService.php',
        'Services/Serpro/SerproProductionOnboardingGuard.php',
        'Services/Serpro/SerproRolloutApprovalService.php',
        'Services/Work/OperationalQueueQuery.php',
        'Services/Work/OperationalTaskStructureService.php',
        'Support/CurrentOffice.php',
    ],
];
