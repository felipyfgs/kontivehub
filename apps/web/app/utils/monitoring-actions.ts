/**
 * Matriz de ações da carteira fiscal (papéis + existência de backend).
 * VIEWER: somente leitura/navegação.
 * OPERATOR: associação, atualização de leitura, export, triagem.
 * ADMIN (+2FA): tudo do OPERATOR + mutações de alto risco.
 */

import type { MeUser, OfficeRole } from '~/types/api'
import {
  canAssociateCategories,
  canCreateExport,
  canExecuteHighRiskMutation,
  canManageClients,
  canTriageMailbox,
  canTriggerSync,
  hasConfirmedAdminAccess
} from '~/utils/permissions'
import type { FiscalModuleKey } from '~/types/fiscal-modules'
import { isFiscalPortfolioModule } from '~/types/fiscal-modules'
import { defaultReadCodesForModule } from '~/utils/fiscal-high-risk'

export type MonitoringActionId
  = | 'add_client'
    | 'associate_categories'
    | 'enqueue_read'
    | 'export_portfolio'
    | 'mailbox_triage'
    | 'high_risk_mutation'

export interface MonitoringActionAvailability {
  id: MonitoringActionId
  allowed: boolean
  reason?: string
}

function roleOf(user?: MeUser | null): OfficeRole | null {
  return user?.role ?? null
}

export function monitoringActionMatrix(user?: MeUser | null): MonitoringActionAvailability[] {
  const role = roleOf(user)
  const isViewer = role === 'VIEWER' || role == null

  return [
    {
      id: 'add_client',
      allowed: canManageClients(user),
      reason: isViewer ? 'VIEWER não pode cadastrar clientes.' : undefined
    },
    {
      id: 'associate_categories',
      allowed: canAssociateCategories(user),
      reason: isViewer ? 'VIEWER não pode associar categorias.' : undefined
    },
    {
      id: 'enqueue_read',
      allowed: canTriggerSync(user),
      reason: isViewer ? 'VIEWER não pode enfileirar consultas.' : undefined
    },
    {
      id: 'export_portfolio',
      allowed: canCreateExport(user),
      reason: isViewer ? 'VIEWER não pode exportar.' : undefined
    },
    {
      id: 'mailbox_triage',
      allowed: canTriageMailbox(user),
      reason: isViewer ? 'VIEWER não pode alterar triagem.' : undefined
    },
    {
      id: 'high_risk_mutation',
      allowed: canExecuteHighRiskMutation(user),
      reason: role === 'OPERATOR'
        ? 'Somente ADMIN com 2FA executa mutações fiscais.'
        : isViewer
          ? 'VIEWER não executa mutações fiscais.'
          : !hasConfirmedAdminAccess(user)
              ? 'ADMIN precisa de 2FA confirmado.'
              : undefined
    }
  ]
}

export function isMonitoringActionAllowed(
  user: MeUser | null | undefined,
  action: MonitoringActionId
): boolean {
  return monitoringActionMatrix(user).find(a => a.id === action)?.allowed === true
}

/**
 * Verifica se o módulo tem endpoint de atualização de leitura implementado.
 * Sem endpoint → UI não desenha botão decorativo.
 */
export function moduleSupportsEnqueueRead(moduleKey: FiscalModuleKey | string): boolean {
  if (moduleKey === 'fgts') {
    // Endpoint dedicado /fiscal/fgts/sync
    return true
  }
  if (moduleKey === 'sitfis') {
    // Endpoint dedicado /fiscal/sitfis/refresh
    return true
  }
  return defaultReadCodesForModule(moduleKey) !== null
}

export function moduleSupportsPortfolioExport(moduleKey: FiscalModuleKey | string): boolean {
  return isFiscalPortfolioModule(moduleKey)
}

export function monitoringBulkActionState(input: {
  moduleKey: string | null
  selectedCount: number
  canAssociate: boolean
  canEnqueue: boolean
  canExport: boolean
  canMembership?: boolean
}) {
  const supported = Boolean(input.moduleKey && isFiscalPortfolioModule(input.moduleKey))
  const associate = supported && input.canAssociate
  const enqueue = supported && input.canEnqueue
    && moduleSupportsEnqueueRead(input.moduleKey || '')
  const exportPortfolio = supported && input.canExport
    && moduleSupportsPortfolioExport(input.moduleKey || '')
  const membership = supported && Boolean(input.canMembership)
  const available = associate || enqueue || exportPortfolio || membership
  return {
    associate,
    enqueue,
    export: exportPortfolio,
    membership,
    available,
    visible: available && (input.selectedCount > 0 || membership)
  }
}
