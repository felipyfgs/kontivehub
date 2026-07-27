import type { FiscalModuleAvailabilityState, FiscalModuleRestrictionControl } from '~/types/api'

export const FISCAL_MODULE_STATE_LABELS: Record<FiscalModuleAvailabilityState, string> = {
  AVAILABLE: 'Disponível',
  GLOBALLY_RESTRICTED: 'Restrito globalmente',
  TENANT_RESTRICTED: 'Restrito para este escritório',
  AWAITING_CONFIGURATION: 'Aguardando configuração',
  TECHNICAL_FAILURE: 'Falha técnica'
}

export function fiscalModuleStateLabel(state: FiscalModuleAvailabilityState): string {
  return FISCAL_MODULE_STATE_LABELS[state] || state
}

export function fiscalModuleStateColor(state: FiscalModuleAvailabilityState) {
  if (state === 'AVAILABLE') return 'success' as const
  if (state === 'AWAITING_CONFIGURATION') return 'warning' as const
  if (state === 'TENANT_RESTRICTED') return 'warning' as const
  return 'error' as const
}

export function fiscalRestrictionActor(control: FiscalModuleRestrictionControl | null): string {
  return control?.updated_by?.name || '—'
}

export function fiscalRestrictionDate(control: FiscalModuleRestrictionControl | null): string | null {
  return control?.updated_at || control?.restricted_at || null
}

const FISCAL_CONTROL_MODULE_KEYS = new Set([
  'simples_mei',
  'dctfweb',
  'installments',
  'sitfis',
  'mailbox',
  'declarations',
  'guides',
  'fgts',
  'registrations',
  'tax_processes'
])

export function fiscalControlModuleKey(
  moduleKey?: string | null,
  surface?: string | null
): string | null {
  const source = moduleKey || surface?.replace(/^monitoring\./, '') || ''
  return FISCAL_CONTROL_MODULE_KEYS.has(source) ? source : null
}
