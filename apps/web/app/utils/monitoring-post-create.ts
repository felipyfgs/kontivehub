/** Destino pós-create conforme tax_regime. */
export function monitoringDestinationAfterClientCreate(taxRegime?: string | null): { path: string } | null {
  const key = String(taxRegime || '').trim().toUpperCase()
  if (!key || key === 'NONE') return null
  if (key === 'MEI' || key === 'SIMEI') {
    return { path: '/monitoring/mei' }
  }
  if (
    key === 'SIMPLES_NACIONAL'
    || key === 'SIMPLES'
    || key === 'SN'
    || key === 'SIMPLES_NAC'
  ) {
    return { path: '/monitoring/simples' }
  }
  return null
}
