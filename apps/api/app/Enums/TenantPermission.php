<?php

namespace App\Enums;

/**
 * Catálogo canônico de permissões tenant (versionável no código).
 *
 * Tenants escolhem chaves existentes; não criam chaves arbitrárias.
 * Baseline de `tenant_admin` e paridade privilegiada cobrem todas as chaves ativas
 * sem exigir perfil. `tenant_user` recebe apenas o subconjunto do perfil.
 *
 * @see openspec/changes/padronizar-autorizacao-multitenant/design.md D4
 */
enum TenantPermission: string
{
    // ── Tenant / governança ──────────────────────────────────────────────
    case TenantDashboardView = 'tenant.dashboard.view';
    case TenantSettingsView = 'tenant.settings.view';
    case TenantSettingsManage = 'tenant.settings.manage';
    case TenantUsersView = 'tenant.users.view';
    case TenantUsersCreate = 'tenant.users.create';
    case TenantUsersManage = 'tenant.users.manage';
    case TenantModulesManage = 'tenant.modules.manage';
    case TenantPermissionProfilesManage = 'tenant.permission_profiles.manage';
    case TenantRolesAssignAdmin = 'tenant.roles.assign_admin';

    // ── Clientes / credenciais ───────────────────────────────────────────
    case ClientsView = 'clients.view';
    case ClientsManage = 'clients.manage';
    case ClientCategoriesManage = 'clients.categories.manage';
    case CredentialsStatusView = 'credentials.status.view';
    case CredentialsManage = 'credentials.manage';

    // ── Fiscal ───────────────────────────────────────────────────────────
    case FiscalDocumentsView = 'fiscal.documents.view';
    case FiscalMonitoringView = 'fiscal.monitoring.view';
    case FiscalSyncTrigger = 'fiscal.sync.trigger';
    case FiscalNfeManifest = 'fiscal.nfe.manifest';
    case DocumentsImport = 'documents.import';
    case ExportsCreate = 'exports.create';
    case FiltersShare = 'filters.share';
    case FiscalMutationsExecute = 'fiscal.mutations.execute';

    // ── Operações ────────────────────────────────────────────────────────
    case OperationsView = 'operations.view';
    case OperationsTriage = 'operations.triage';

    // ── Comunicação ─────────────────────────────────────────────────────
    case CommunicationView = 'communication.view';
    case CommunicationReply = 'communication.reply';
    case CommunicationManageInboxes = 'communication.manage_inboxes';
    case CommunicationManageContacts = 'communication.manage_contacts';
    case CommunicationManageQuickReplies = 'communication.manage_quick_replies';
    case CommunicationManageFlows = 'communication.manage_flows';
    /** @deprecated Compatibilidade de leitura para perfis persistidos antes da chave canônica. */
    case CommunicationManage = 'communication.manage';

    // ── Work ─────────────────────────────────────────────────────────────
    case WorkView = 'work.view';
    case WorkCatalogManage = 'work.catalog.manage';
    case WorkProcessesCreate = 'work.processes.create';
    case WorkTasksExecute = 'work.tasks.execute';
    case WorkAdminister = 'work.administer';
    case WorkEvidenceDownload = 'work.evidence.download';
    case WorkExportsCreate = 'work.exports.create';

    public function label(): string
    {
        return match ($this) {
            self::TenantDashboardView => 'Ver painel do tenant',
            self::TenantSettingsView => 'Ver configurações do tenant',
            self::TenantSettingsManage => 'Gerenciar configurações do tenant',
            self::TenantUsersView => 'Listar equipe',
            self::TenantUsersCreate => 'Criar usuários do tenant',
            self::TenantUsersManage => 'Gerenciar usuários do tenant',
            self::TenantModulesManage => 'Gerenciar módulos do tenant',
            self::TenantPermissionProfilesManage => 'Gerenciar perfis de permissão',
            self::TenantRolesAssignAdmin => 'Atribuir administrador do tenant',
            self::ClientsView => 'Ver clientes',
            self::ClientsManage => 'Gerenciar clientes',
            self::ClientCategoriesManage => 'Gerenciar categorias de clientes',
            self::CredentialsStatusView => 'Ver status de credenciais',
            self::CredentialsManage => 'Gerenciar credenciais',
            self::FiscalDocumentsView => 'Ver documentos fiscais',
            self::FiscalMonitoringView => 'Ver monitoramento fiscal',
            self::FiscalSyncTrigger => 'Disparar sincronização fiscal',
            self::FiscalNfeManifest => 'Manifestar NF-e',
            self::DocumentsImport => 'Importar documentos',
            self::ExportsCreate => 'Criar exportações',
            self::FiltersShare => 'Compartilhar filtros salvos',
            self::FiscalMutationsExecute => 'Executar mutações fiscais',
            self::OperationsView => 'Ver operações / inbox',
            self::OperationsTriage => 'Triagem operacional',
            self::CommunicationView => 'Ver atendimento e conversas',
            self::CommunicationReply => 'Responder e triar conversas',
            self::CommunicationManageInboxes,
            self::CommunicationManage => 'Administrar canais e políticas de comunicação',
            self::CommunicationManageContacts => 'Gerenciar contatos de comunicação',
            self::CommunicationManageQuickReplies => 'Gerenciar respostas rápidas de comunicação',
            self::CommunicationManageFlows => 'Gerenciar fluxos de comunicação',
            self::WorkView => 'Ver módulo operacional',
            self::WorkCatalogManage => 'Gerenciar catálogo Work',
            self::WorkProcessesCreate => 'Criar processos operacionais',
            self::WorkTasksExecute => 'Executar tarefas operacionais',
            self::WorkAdminister => 'Administrar Work',
            self::WorkEvidenceDownload => 'Baixar evidências Work',
            self::WorkExportsCreate => 'Exportar Work',
        };
    }

    /**
     * Módulo lógico para agrupamento em UI/auditoria.
     */
    public function module(): string
    {
        return match ($this) {
            self::TenantDashboardView,
            self::TenantSettingsView,
            self::TenantSettingsManage,
            self::TenantUsersView,
            self::TenantUsersCreate,
            self::TenantUsersManage,
            self::TenantModulesManage,
            self::TenantPermissionProfilesManage,
            self::TenantRolesAssignAdmin => 'tenant',
            self::ClientsView,
            self::ClientsManage,
            self::ClientCategoriesManage => 'clients',
            self::CredentialsStatusView,
            self::CredentialsManage => 'credentials',
            self::FiscalDocumentsView,
            self::FiscalMonitoringView,
            self::FiscalSyncTrigger,
            self::FiscalNfeManifest,
            self::DocumentsImport,
            self::ExportsCreate,
            self::FiltersShare,
            self::FiscalMutationsExecute => 'fiscal',
            self::OperationsView,
            self::OperationsTriage => 'operations',
            self::CommunicationView,
            self::CommunicationReply,
            self::CommunicationManageInboxes,
            self::CommunicationManageContacts,
            self::CommunicationManageQuickReplies,
            self::CommunicationManageFlows,
            self::CommunicationManage => 'communication',
            self::WorkView,
            self::WorkCatalogManage,
            self::WorkProcessesCreate,
            self::WorkTasksExecute,
            self::WorkAdminister,
            self::WorkEvidenceDownload,
            self::WorkExportsCreate => 'work',
        };
    }

    /**
     * Risco operacional da capacidade (não substitui kill switch / guards).
     *
     * @return 'low'|'medium'|'high'
     */
    public function risk(): string
    {
        return match ($this) {
            self::TenantDashboardView,
            self::TenantSettingsView,
            self::TenantUsersView,
            self::ClientsView,
            self::CredentialsStatusView,
            self::FiscalDocumentsView,
            self::FiscalMonitoringView,
            self::OperationsView,
            self::CommunicationView,
            self::WorkView => 'low',

            self::TenantSettingsManage,
            self::TenantUsersCreate,
            self::TenantUsersManage,
            self::TenantModulesManage,
            self::ClientsManage,
            self::ClientCategoriesManage,
            self::FiscalSyncTrigger,
            self::FiscalNfeManifest,
            self::DocumentsImport,
            self::ExportsCreate,
            self::FiltersShare,
            self::OperationsTriage,
            self::CommunicationReply,
            self::WorkCatalogManage,
            self::WorkProcessesCreate,
            self::WorkTasksExecute,
            self::WorkEvidenceDownload,
            self::WorkExportsCreate => 'medium',

            self::TenantPermissionProfilesManage,
            self::TenantRolesAssignAdmin,
            self::CredentialsManage,
            self::FiscalMutationsExecute,
            self::CommunicationManageInboxes,
            self::CommunicationManageContacts,
            self::CommunicationManageQuickReplies,
            self::CommunicationManageFlows,
            self::CommunicationManage,
            self::WorkAdminister => 'high',
        };
    }

    /**
     * Se `tenant_user` com permissão de criar usuários pode delegar esta chave
     * a um perfil-alvo (subconjunto). Chaves reservadas a `tenant_admin` retornam false.
     */
    public function isDelegable(): bool
    {
        return match ($this) {
            self::TenantPermissionProfilesManage,
            self::TenantRolesAssignAdmin => false,
            default => true,
        };
    }

    public function isActive(): bool
    {
        return true;
    }

    /**
     * Conjunto exato do perfil de sistema `legacy-operator` (paridade OPERATOR).
     *
     * @return list<self>
     */
    public static function legacyOperatorSet(): array
    {
        return [
            self::TenantDashboardView,
            self::TenantSettingsView,
            self::ClientsView,
            self::ClientsManage,
            self::CredentialsStatusView,
            self::FiscalDocumentsView,
            self::FiscalMonitoringView,
            self::FiscalSyncTrigger,
            self::FiscalNfeManifest,
            self::DocumentsImport,
            self::ExportsCreate,
            self::FiltersShare,
            self::OperationsView,
            self::OperationsTriage,
            self::CommunicationView,
            self::CommunicationReply,
            self::WorkView,
            self::WorkProcessesCreate,
            self::WorkTasksExecute,
            self::WorkEvidenceDownload,
            self::WorkExportsCreate,
        ];
    }

    /**
     * Conjunto exato do perfil de sistema `legacy-viewer` (paridade VIEWER / somente leitura).
     *
     * @return list<self>
     */
    public static function legacyViewerSet(): array
    {
        return [
            self::TenantDashboardView,
            self::TenantSettingsView,
            self::ClientsView,
            self::CredentialsStatusView,
            self::FiscalDocumentsView,
            self::FiscalMonitoringView,
            self::OperationsView,
            self::CommunicationView,
            self::WorkView,
        ];
    }

    /**
     * Mapeamento estável OfficeRole::can* → chave canônica (baseline de migração).
     *
     * @return array<string, self>
     */
    public static function officeRoleMethodMap(): array
    {
        return [
            'canManageClients' => self::ClientsManage,
            'canManageCredentials' => self::CredentialsManage,
            'canTriggerSync' => self::FiscalSyncTrigger,
            'canManifestNfe' => self::FiscalNfeManifest,
            'canExport' => self::ExportsCreate,
            'canShareListFilters' => self::FiltersShare,
            'canImportDocuments' => self::DocumentsImport,
            'canMutateFiscal' => self::FiscalMutationsExecute,
            'canManageWorkCatalog' => self::WorkCatalogManage,
            'canCreateWorkProcesses' => self::WorkProcessesCreate,
            'canExecuteWorkTasks' => self::WorkTasksExecute,
            'canAdministerWork' => self::WorkAdminister,
            'canViewWork' => self::WorkView,
            'canDownloadWorkEvidence' => self::WorkEvidenceDownload,
            'canExportWork' => self::WorkExportsCreate,
        ];
    }

    /**
     * @return list<string>
     */
    public static function orderedValues(): array
    {
        $values = array_map(static fn (self $p) => $p->value, self::cases());
        sort($values, SORT_STRING);

        return $values;
    }
}
