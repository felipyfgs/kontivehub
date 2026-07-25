/**
 * Códigos oficiais de operações fiscais de alto risco.
 * Fonte: catálogos backend (DctfwebCodes, tax_guides.operations, SerproServiceCatalog).
 * NUNCA inventar fallback de solution/service/operation na UI.
 */

import type { FiscalModuleKey } from '~/types/fiscal-modules'

/** Payload mínimo para preflight/execute — só com códigos oficiais. */
export interface OfficialMutationCodes {
  solution_code: string
  service_code: string
  operation_code: string
  module?: string
}

/** DCTFWeb — transmissão (DctfwebCodes). */
export const DCTFWEB_TRANSMIT: OfficialMutationCodes = {
  solution_code: 'INTEGRA_DCTFWEB',
  service_code: 'DCTFWEB',
  operation_code: 'TRANSMITIR_DECLARACAO',
  module: 'dctfweb'
}

/** MIT — encerramento (DctfwebCodes). */
export const MIT_ENCERRAR: OfficialMutationCodes = {
  solution_code: 'INTEGRA_MIT',
  service_code: 'MIT',
  operation_code: 'ENCERRAR',
  module: 'mit'
}

/**
 * Catálogo local de emissão de guias (config/tax_guides.php operations).
 * Chave: system|service|operation
 */
const GUIDE_EMISSION_OPS = new Set([
  'INTEGRA_PAGAMENTO|SICALC|EMITIR_GUIA',
  'INTEGRA_CONTADOR|GUIAS|EMITIR_GUIA',
  'INTEGRA_SN|PGDASD|EMITIR_DAS',
  'INTEGRA_MEI|PGMEI|EMITIR_DAS',
  'INTEGRA_PARCELAMENTO|PARCSN|EMITIR_DOCUMENTO'
])

function norm(v: unknown): string {
  return String(v ?? '').trim().toUpperCase()
}

/**
 * Resolve códigos de mutação a partir da linha da API.
 * Sem fallback inventado: se faltar qualquer código, retorna null (UI não oferece ação).
 */
export function resolveHighRiskCodesFromRow(
  row: Record<string, unknown> | null | undefined,
  moduleHint?: 'dctfweb' | 'mit' | 'guides' | string | null
): OfficialMutationCodes | null {
  if (!row) return null

  const solution = norm(row.solution_code || row.system_code)
  const service = norm(row.service_code)
  const operation = norm(row.operation_code)
  const hint = String(moduleHint || row.module_key || '').toLowerCase()

  // DCTFWeb/MIT: preferir catálogo oficial quando o módulo é conhecido
  if (hint === 'mit' || service === 'MIT' || solution === 'INTEGRA_MIT') {
    if (!row.client_id) return null
    // Só aceita se a linha confirma MIT ou usa o código oficial completo
    if (operation && operation !== 'ENCERRAR' && !operation.includes('ENCERRAR')) {
      // operação desconhecida na linha — recusa (sem inventar)
      if (solution && service && operation) {
        return {
          solution_code: solution,
          service_code: service,
          operation_code: operation,
          module: 'mit'
        }
      }
      return null
    }
    return { ...MIT_ENCERRAR }
  }

  if (hint === 'dctfweb' || service === 'DCTFWEB' || solution === 'INTEGRA_DCTFWEB') {
    if (!row.client_id) return null
    if (operation && operation !== 'TRANSMITIR_DECLARACAO' && operation !== 'TRANSMITIR') {
      if (solution && service && operation) {
        return {
          solution_code: solution,
          service_code: service,
          operation_code: operation,
          module: 'dctfweb'
        }
      }
      return null
    }
    // TRANSMITIR legado na linha → normaliza para código oficial
    return { ...DCTFWEB_TRANSMIT }
  }

  if (hint === 'guides' || hint === 'guias') {
    return resolveGuideEmissionCodes(row)
  }

  // Genérico: exige os três códigos oficiais na própria linha
  if (!solution || !service || !operation) return null
  return {
    solution_code: solution,
    service_code: service,
    operation_code: operation,
    module: hint || undefined
  }
}

export function resolveGuideEmissionCodes(
  row: Record<string, unknown> | null | undefined
): OfficialMutationCodes | null {
  if (!row?.client_id) return null

  const solution = norm(row.solution_code || row.system_code)
  const service = norm(row.service_code)
  const operation = norm(row.operation_code)

  if (!solution || !service || !operation) {
    return null
  }

  const key = `${solution}|${service}|${operation}`
  if (!GUIDE_EMISSION_OPS.has(key) && !operation.startsWith('EMITIR_')) {
    return null
  }

  // EMITIR_* fora do set local ainda pode ser catálogo SERPRO — exige os três
  return {
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
