/**
 * Matriz de ações da carteira fiscal por permissões efetivas.
 */

import type { MeUser } from '~/types/api'
import {
  canAssociateCategories,
  canCreateExport,
  canExecuteHighRiskMutation,
  canManageClients,
  canTriageMailbox,
  canTriggerSync
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

export function monitoringActionMatrix(user?: MeUser | null): MonitoringActionAvailability[] {
  return [
    {
      id: 'add_client',
      allowed: canManageClients(user),
      reason: canManageClients(user) ? undefined : 'Sem permissão para cadastrar clientes.'
    },
    {
      id: 'associate_categories',
      allowed: canAssociateCategories(user),
      reason: canAssociateCategories(user) ? undefined : 'Sem permissão para associar categorias.'
    },
    {
      id: 'enqueue_read',
      allowed: canTriggerSync(user),
      reason: canTriggerSync(user) ? undefined : 'Sem permissão para enfileirar consultas.'
    },
    {
      id: 'export_portfolio',
      allowed: canCreateExport(user),
      reason: canCreateExport(user) ? undefined : 'Sem permissão para exportar.'
    },
    {
      id: 'mailbox_triage',
      allowed: canTriageMailbox(user),
      reason: canTriageMailbox(user) ? undefined : 'Sem permissão para alterar triagem.'
    },
    {
      id: 'high_risk_mutation',
      allowed: canExecuteHighRiskMutation(user),
      reason: canExecuteHighRiskMutation(user)
        ? undefined
        : 'Sem permissão para executar mutações fiscais.'
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
