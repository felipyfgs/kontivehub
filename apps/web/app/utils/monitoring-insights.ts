import type { MonitoringInsightsPayload } from '~/types/monitoring-insights'
import type { DashboardKpiItem } from '~/utils/kpi-ui'

export interface MonitoringKpiOptions {
  loading?: boolean
}

function displayValue(value: number | null | undefined, loading: boolean): number | string {
  if (value != null) return value
  return loading ? '…' : '—'
}

/**
 * Mapeia exclusivamente valores confirmados pelo agregador fiscal.
 * `0` é preservado; ausência/falha vira `—` e carga inicial vira `…`.
 */
export function buildMonitoringKpis(
  payload: MonitoringInsightsPayload | null,
  options: MonitoringKpiOptions = {}
): DashboardKpiItem[] {
  const loading = Boolean(options.loading && !payload)
  const clients = payload?.kpis.clients_total
  const pending = payload?.kpis.pending_open
  const findings = payload?.kpis.findings_active
  const modulesWithError = payload?.kpis.modules_with_error

  return [
    {
      key: 'clients',
      title: 'Empresas no escritório',
      icon: 'i-lucide-building-2',
      value: displayValue(clients, loading),
      to: '/clients',
      tone: 'primary',
      ariaLabel: 'Abrir empresas do escritório'
    },
    {
      key: 'pending',
      title: 'Pendências abertas',
      icon: 'i-lucide-circle-dashed',
      value: displayValue(pending, loading),
      to: '/monitoring/sitfis',
      critical: (pending ?? 0) > 0,
      tone: 'warning',
      ariaLabel: 'Abrir pendências fiscais'
    },
    {
      key: 'findings',
      title: 'Achados ativos',
      icon: 'i-lucide-triangle-alert',
      value: displayValue(findings, loading),
      to: '/monitoring/sitfis',
      critical: (findings ?? 0) > 0,
      tone: 'warning',
      ariaLabel: 'Abrir achados fiscais ativos'
    },
    {
      key: 'module_errors',
      title: 'Módulos com erro',
      icon: 'i-lucide-circle-x',
      value: displayValue(modulesWithError, loading),
      to: '/monitoring/declarations',
      critical: (modulesWithError ?? 0) > 0,
      tone: 'error',
      ariaLabel: 'Abrir saúde dos módulos fiscais'
    }
  ]
}
