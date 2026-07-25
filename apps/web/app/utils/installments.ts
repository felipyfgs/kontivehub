import type {
  InstallmentModalityCatalogItem,
  InstallmentMonitorBulkResponse
} from '~/types/fiscal-modules'

export function partitionInstallmentCatalog(items: InstallmentModalityCatalogItem[]) {
  return {
    executable: items.filter(item => item.executable && item.monitoring_supported),
    unavailable: items.filter(item => !item.executable || !item.monitoring_supported)
  }
}

export function installmentMonitorFeedback(
  response: InstallmentMonitorBulkResponse | null,
  targetClients: number
) {
  const accepted = Math.max(0, Number(response?.accepted || 0))
  const failed = response
    ? Math.max(0, Number(response.failed || 0))
    : Math.max(0, targetClients) * 8

  return {
    accepted,
    failed,
    title: accepted > 0 ? 'Consulta de parcelamentos solicitada' : 'Nenhuma consulta foi solicitada',
    color: accepted > 0 ? (failed > 0 ? 'warning' : 'success') : 'error'
  } as const
}
