import type { MeResponse, MeUser, TenantRole } from '~/types/api'

export type MeIdentity = MeUser | MeResponse | null | undefined

function isIdentityObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

export function unwrapMeUser(identity: unknown): MeUser | null {
  if (!isIdentityObject(identity)) {
    return null
  }

  const candidate = 'data' in identity ? identity.data : identity

  return isIdentityObject(candidate) ? candidate as unknown as MeUser : null
}

function hasEffectivePermission(
  user: MeUser | null | undefined,
  permission: string
): boolean {
  return Array.isArray(user?.effective_permissions)
    && user.effective_permissions.includes(permission)
}

export function hasTenantAdminAccess(user?: MeUser | null): boolean {
  return user?.tenant_role === 'tenant_admin'
}

export function isPlatformAdmin(user?: MeUser | null): boolean {
  return user?.platform_role === 'platform_admin'
}

export function canAccessPlatformAdmin(user?: MeUser | null): boolean {
  return isPlatformAdmin(user)
}

export function isPlatformPrivilegedContext(user?: MeUser | null): boolean {
  return isPlatformAdmin(user)
    && user?.access_mode === 'platform_privileged'
    && user.current_tenant !== null
}

export function canAccessTenantSettings(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'tenant.settings.view')
}

export function realTenantRole(user?: MeUser | null): TenantRole | null {
  return user?.real_tenant_role ?? null
}

export function canManageClients(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'clients.manage')
}

export function canManageClientCategoryCatalog(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'clients.categories.manage')
}

export function canAssignClientCategories(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'clients.manage')
}

export function canManageCredentials(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'credentials.manage')
}

export function canTriggerSync(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'fiscal.sync.trigger')
}

export function canCreateExport(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'exports.create')
}

export function canImportDocuments(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'documents.import')
}

export function canAssociateCategories(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'clients.manage')
}

export function canTriageMailbox(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'operations.triage')
}

export function canViewCommunication(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.view')
}

export function canReplyCommunication(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.reply')
}

export function canManageCommunication(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.manage_inboxes')
}

export function canManageCommunicationContacts(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.manage_contacts')
}

export function canManageCommunicationQuickReplies(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.manage_quick_replies')
}

export function canManageCommunicationFlows(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.manage_flows')
}

export function canExecuteHighRiskMutation(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'fiscal.mutations.execute')
}

export function canViewWork(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.view')
}

export function canViewFiscal(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'fiscal.monitoring.view')
}

export function canManageWorkCatalog(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.catalog.manage')
}

export function canManageTenantTeam(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'tenant.users.manage')
}

export function canCreateWorkProcesses(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.processes.create')
}

export function canExecuteWorkTasks(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.tasks.execute')
}

export function canAdministerWork(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.administer')
}

export function canExportWork(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.exports.create')
}

export function canDownloadWorkEvidence(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.evidence.download')
}
