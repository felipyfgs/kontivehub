import type { MeResponse, MeUser, OfficeRole } from '~/types/api'

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

/**
 * ADMIN efetivo do office (papel efetivo). TOTP não é mais gate.
 */
export function hasConfirmedAdminAccess(user?: MeUser | null): boolean {
  return user?.role === 'ADMIN'
}

/** Flag global PLATFORM_ADMIN (sem membership fiscal implícita). */
export function isPlatformAdmin(user?: MeUser | null): boolean {
  return Boolean(user?.is_platform_admin)
}

/**
 * Área de plataforma `/admin/*` e console SERPRO.
 */
export function canAccessPlatformAdmin(user?: MeUser | null): boolean {
  return isPlatformAdmin(user)
}

/**
 * @deprecated Prefer `canAccessPlatformAdmin`.
 */
export function canAccessPlatformSerproConsole(user?: MeUser | null): boolean {
  return canAccessPlatformAdmin(user)
}

/** Contexto privilegiado ativo (seletor global com office resolvido). */
export function isPlatformPrivilegedContext(user?: MeUser | null): boolean {
  const office = user?.current_office ?? user?.office
  return isPlatformAdmin(user) && user?.access_mode === 'platform_privileged' && !!office
}

/**
 * Configuração do escritório (`/settings`): ADMIN efetivo, ou PLATFORM_ADMIN
 * em contexto privilegiado com office selecionado.
 */
export function canAccessOfficeSettings(user?: MeUser | null): boolean {
  if (hasConfirmedAdminAccess(user)) return true
  return isPlatformPrivilegedContext(user)
}

function roleCanMutate(role?: OfficeRole | null): boolean {
  return role === 'ADMIN' || role === 'OPERATOR'
}

function hasMutationAccess(user?: MeUser | null): boolean {
  return roleCanMutate(user?.role)
}

/**
 * Papel real da membership (Work mutações).
 * Em membership (ou access_mode ausente legado) usa role; em privilegiado exige real_office_role.
 */
export function realOfficeRole(user?: MeUser | null): OfficeRole | null {
  if (user?.real_office_role) {
    return user.real_office_role
  }
  if (user?.access_mode === 'platform_privileged') {
    return user.has_real_membership ? (user.role ?? null) : null
  }
  // membership ou payload legado sem access_mode
  return user?.role ?? null
}

/**
 * Mutação Work no office corrente.
 * PLATFORM_ADMIN em contexto privilegiado atua com o papel efetivo (ADMIN).
 * Membership real: papel real da OfficeMembership.
 */
function hasRealWorkMutationAccess(user?: MeUser | null): boolean {
  if (isPlatformPrivilegedContext(user)) {
    return roleCanMutate(user?.role ?? 'ADMIN')
  }
  return roleCanMutate(realOfficeRole(user))
}

/**
 * Superfície de ADMIN do escritório (nav + ações).
 * Office ADMIN (efetivo/real) ou PLATFORM_ADMIN com office selecionado.
 */
export function hasOfficeAdminSurface(user?: MeUser | null): boolean {
  if (hasConfirmedAdminAccess(user)) return true
  if (realOfficeRole(user) === 'ADMIN') return true
  return isPlatformPrivilegedContext(user)
}

export function canManageClients(user?: MeUser | null): boolean {
  return hasMutationAccess(user)
}

function hasEffectivePermission(user: MeUser | null | undefined, permission: string): boolean | null {
  if (!Array.isArray(user?.effective_permissions)) return null
  return user.effective_permissions.includes(permission)
}

/** Catálogo livre de categorias: permissão dedicada, ADMIN no payload legado. */
export function canManageClientCategoryCatalog(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'clients.categories.manage') ?? hasOfficeAdminSurface(user)
}

/** Atribuir/remover categorias usa a mesma autoridade de clients.manage. */
export function canAssignClientCategories(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'clients.manage') ?? canManageClients(user)
}

export function canManageCredentials(user?: MeUser | null): boolean {
  return hasConfirmedAdminAccess(user)
}

export function canTriggerSync(user?: MeUser | null): boolean {
  return hasMutationAccess(user)
}

export function canCreateExport(user?: MeUser | null): boolean {
  return hasMutationAccess(user)
}

/** Importação de XML de saída (mesmo perfil de mutação que export). */
export function canImportDocuments(user?: MeUser | null): boolean {
  return hasMutationAccess(user)
}

/** Associação de categorias fiscais (ADMIN/OPERATOR). */
export function canAssociateCategories(user?: MeUser | null): boolean {
  return hasMutationAccess(user)
}

/** Triagem interna da Caixa Postal (ADMIN/OPERATOR). */
export function canTriageMailbox(user?: MeUser | null): boolean {
  return hasMutationAccess(user)
}

/** Atendimento compartilhado: leitura é uma capacidade explícita do perfil. */
export function canViewCommunication(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.view')
    ?? (!!user?.role || isPlatformPrivilegedContext(user))
}

/** Responder, anotar e triar conversas. */
export function canReplyCommunication(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'communication.reply')
    ?? hasMutationAccess(user)
}

/** Canais, membros, catálogos, retenção e políticas de automação. */
export function canManageCommunication(user?: MeUser | null): boolean {
  const canonical = hasEffectivePermission(user, 'communication.manage_inboxes')
  if (canonical !== null) {
    return canonical || hasEffectivePermission(user, 'communication.manage') === true
  }
  return hasOfficeAdminSurface(user)
}

/**
 * Mutações do catálogo de contatos (create/edit/identity/link/export/purge).
 * Não herda de manage_inboxes — capability dedicada.
 */
export function canManageCommunicationContacts(user?: MeUser | null): boolean {
  const canonical = hasEffectivePermission(user, 'communication.manage_contacts')
  if (canonical !== null) return canonical
  return hasOfficeAdminSurface(user)
}

/**
 * Mutações de respostas rápidas (create/edit/duplicate/deactivate).
 * Não herda de manage_inboxes nem manage_contacts.
 */
export function canManageCommunicationQuickReplies(user?: MeUser | null): boolean {
  const canonical = hasEffectivePermission(user, 'communication.manage_quick_replies')
  if (canonical !== null) return canonical
  return hasOfficeAdminSurface(user)
}

/**
 * Mutações de fluxos/robôs (CRUD, draft, validate, publish, bindings).
 * Não herda de manage_inboxes, manage_contacts nem manage_quick_replies.
 */
export function canManageCommunicationFlows(user?: MeUser | null): boolean {
  const canonical = hasEffectivePermission(user, 'communication.manage_flows')
  if (canonical !== null) return canonical
  return hasOfficeAdminSurface(user)
}

/**
 * Mutações fiscais de alto risco (emissão/transmissão).
 * Somente ADMIN efetivo — senha recente é gate no backend.
 */
export function canExecuteHighRiskMutation(user?: MeUser | null): boolean {
  return hasConfirmedAdminAccess(user)
}

// ── Módulo operacional (Work) — ocultação UI ≠ autorização ────────────────
// Preferir effective_permissions; fallback legado alinhado a TenantPermission.
// Mutação/export: membership real no fallback (nunca mais permissivo que a policy).

export function canViewWork(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.view')
    ?? (!!user?.role || isPlatformPrivilegedContext(user))
}

/** Acesso de leitura à área Fiscal (Monitoramento). */
export function canViewFiscal(user?: MeUser | null): boolean {
  return !!user?.role || isPlatformPrivilegedContext(user)
}

export function canManageWorkCatalog(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.catalog.manage')
    ?? hasOfficeAdminSurface(user)
}

/**
 * Gestão de equipe do escritório (`/conta/equipe`).
 * Office ADMIN ou PLATFORM_ADMIN com office selecionado (paridade de superfície).
 */
export function canManageOfficeTeam(user?: MeUser | null): boolean {
  return hasOfficeAdminSurface(user)
}

export function canCreateWorkProcesses(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.processes.create')
    ?? hasRealWorkMutationAccess(user)
}

export function canExecuteWorkTasks(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.tasks.execute')
    ?? hasRealWorkMutationAccess(user)
}

export function canAdministerWork(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.administer')
    ?? hasOfficeAdminSurface(user)
}

export function canExportWork(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.exports.create')
    ?? hasRealWorkMutationAccess(user)
}

export function canDownloadWorkEvidence(user?: MeUser | null): boolean {
  return hasEffectivePermission(user, 'work.evidence.download')
    ?? hasRealWorkMutationAccess(user)
}

export function isWorkOperator(user?: MeUser | null): boolean {
  const role = realOfficeRole(user) ?? user?.role
  return role === 'OPERATOR'
}
