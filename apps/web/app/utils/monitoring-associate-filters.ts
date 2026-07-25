/**
 * Filtros de listagem do modal «Associar clientes» por carteira.
 * PGDASD → só Simples Nacional; PGMEI → só MEI; demais → sem filtro de regime.
 */
export function monitoringAssociateClientListFilters(
  moduleKey: string,
  submodule?: string | null
): { tax_regimes?: string, is_active: true } {
  const base = { is_active: true as const }
  const module = String(moduleKey || '').toLowerCase()
  const sub = String(submodule || '').toUpperCase()

  if (module === 'simples_mei' || module === 'simples-mei') {
    if (['PGMEI', 'MEI'].includes(sub)) {
      return { ...base, tax_regimes: 'MEI' }
    }
    if (['PGDASD', 'PGDAS', 'SIMPLES', 'SIMPLES_NACIONAL'].includes(sub) || sub === '') {
      return { ...base, tax_regimes: 'SIMPLES_NACIONAL' }
    }
  }

  return base
}

export function monitoringAssociateScopeLabel(
  moduleKey: string,
  submodule?: string | null
): string {
  const filters = monitoringAssociateClientListFilters(moduleKey, submodule)
  if (filters.tax_regimes === 'MEI') return 'apenas clientes MEI'
  if (filters.tax_regimes === 'SIMPLES_NACIONAL') return 'apenas clientes Simples Nacional'
  return 'clientes ativos do escritório'
}
