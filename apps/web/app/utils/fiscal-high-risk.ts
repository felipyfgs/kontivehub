/**
 * Códigos oficiais de operações fiscais de alto risco.
 * Fonte: catálogos backend (DctfwebCodes, tax_guides.operations, SerproServiceCatalog).
 * NUNCA inventar fallback de solution/service/operation na UI.
 */

import type { FiscalModuleKey } from '~/types/fiscal-modules'

/** Payload mínimo para preflight/execute — só com códigos oficiais. */
export interface OfficialMutationCodes {
  operation_key: string
  solution_code: string
  service_code: string
  operation_code: string
  module?: string
}

function norm(v: unknown): string {
  return String(v ?? '').trim().toUpperCase()
}

export function resolveGuideEmissionCodes(
  row: Record<string, unknown> | null | undefined
): OfficialMutationCodes | null {
  if (!row?.client_id) return null

  const operationKey = String(row.operation_key || '').trim()
  const solution = norm(row.system_code)
  const service = norm(row.service_code)
  const operation = norm(row.operation_code)

  if (!operationKey || !solution || !service || !operation) {
    return null
  }

  if (!operation.startsWith('EMITIR_') && operation !== 'GERAR_GUIA') {
    return null
  }

  return {
    operation_key: operationKey,
    solution_code: solution,
    service_code: service,
    operation_code: operation,
    module: 'guides'
  }
}

/**
 * Códigos de leitura (MONITOR) por módulo — para enqueue de atualização.
 * Derivados do seed fiscal_categories / fiscal_monitoring config.
 */
export function defaultReadCodesForModule(
  moduleKey: FiscalModuleKey | string
): { system_code: string, service_code: string, operation_code: string } | null {
  switch (moduleKey) {
    case 'simples_mei':
      return { system_code: 'INTEGRA_SN', service_code: 'PGDASD', operation_code: 'MONITOR' }
    case 'dctfweb':
      return { system_code: 'INTEGRA_DCTFWEB', service_code: 'DCTFWEB', operation_code: 'MONITOR' }
    case 'installments':
      return { system_code: 'INTEGRA_PARCELAMENTO', service_code: 'PARCSN', operation_code: 'MONITOR' }
    case 'sitfis':
      return { system_code: 'INTEGRA_SITFIS', service_code: 'SITFIS', operation_code: 'MONITOR' }
    case 'mailbox':
      return { system_code: 'INTEGRA_CAIXAPOSTAL', service_code: 'CAIXA_POSTAL', operation_code: 'LISTAR' }
    case 'declarations':
      return { system_code: 'INTEGRA_CONTADOR', service_code: 'DECLARACOES', operation_code: 'MONITOR' }
    case 'guides':
      return { system_code: 'INTEGRA_CONTADOR', service_code: 'GUIAS', operation_code: 'MONITOR' }
    case 'fgts':
      // FGTS usa endpoint dedicado /fiscal/fgts/sync — não inventar run genérico
      return null
    default:
      return null
  }
}
