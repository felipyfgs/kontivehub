/**
 * Mapeamento fail-closed dos blocos do cockpit Início.
 * Loading / ausência de summary NÃO vira zero inventado.
 */
import type {
  OperationsBlocks,
  OperationsCommunicationSummary,
  OperationsFiscalPending,
  OperationsPlatformHealth,
  OperationsProxyPowers,
  OperationsSerproAuthorization,
  OperationsSummary,
  OperationsUsageSummary
} from '~/types/api'
import type { DashboardKpiItem } from '~/utils/kpi-ui'
import { validateTenantPath } from '~/utils/inbox-links'

export function homeDisplayValue(
  loading: boolean,
  hasSummary: boolean,
  value: number | undefined | null
): string | number {
  if (loading && !hasSummary) return '…'
  if (!hasSummary) return '—'
  return value ?? 0
}

export function homeBlocksBanner(summary: OperationsSummary | null): {
  show: boolean
  title: string
  description?: string
  tone: 'error' | 'warning'
  to: string
} | null {
  if (!summary) return null
  const blocks = summary.blocks
  const health = summary.platform_health
  const modules = summary.modules

  if (blocks?.blocked || (blocks?.reasons?.length ?? 0) > 0) {
    const reasons = blocks?.reasons?.join(', ') || 'Bloqueio operacional'
    return {
      show: true,
      title: blocks?.blocked ? 'Escritório com bloqueios ativos' : 'Atenção operacional',
      description: reasons,
      tone: blocks?.blocked ? 'error' : 'warning',
      to: resolveBlocksDeepLink(blocks, summary.serpro_authorization)
    }
  }

  if (health && (!health.available || health.kill_switch || health.circuit_open)) {
    return {
      show: true,
      title: 'Integra Contador indisponível ou degradado',
      description: [
        health.status,
        health.kill_switch ? 'kill switch' : null,
        health.circuit_open ? 'circuit open' : null
      ].filter(Boolean).join(' · '),
      tone: 'error',
      to: '/health'
    }
  }

  if (modules?.kill_switch) {
    return {
      show: true,
      title: 'Kill switch de módulos ativo',
      description: 'Consultas e mutações do hub podem estar contidas.',
      tone: 'warning',
      to: '/health'
    }
  }

  return null
}

function resolveBlocksDeepLink(
  blocks: OperationsBlocks | undefined,
  auth: OperationsSerproAuthorization | undefined
): string {
  const next = blocks?.next_action || auth?.next_action
  if (next && String(next).includes('AUTHOR')) return '/conta/escritorio'
  if (next && String(next).includes('AUTH')) return '/conta/escritorio'
  if (blocks?.reasons?.some(r => r.includes('USAGE'))) return '/conta/consumo'
  return '/health'
}

export function buildHomeFiscalKpis(
  summary: OperationsSummary | null,
  options?: { loading?: boolean }
): DashboardKpiItem[] {
  const loading = Boolean(options?.loading && !summary)
  const pending = summary?.fiscal_pending as OperationsFiscalPending | undefined
  const n = (v: number | undefined) => homeDisplayValue(Boolean(options?.loading), !!summary, v)

  return [
    {
      key: 'fiscal_open',
      title: 'Pendências abertas',
      icon: 'i-lucide-list-checks',
      value: n(pending?.open_total),
      to: '/monitoring',
      tone: !loading && (pending?.open_critical ?? 0) > 0 ? 'error' : 'default',
      critical: !loading && (pending?.open_critical ?? 0) > 0
    },
    {
      key: 'fiscal_due_7d',
      title: 'Pendências 7d',
      icon: 'i-lucide-calendar-clock',
      value: n(pending?.due_7d),
      to: '/monitoring',
      tone: !loading && (pending?.due_7d ?? 0) > 0 ? 'warning' : 'default'
    },
    {
      key: 'guides_due',
      title: 'Guias a vencer',
      icon: 'i-lucide-receipt',
      value: n(summary?.guides_due_7d),
      to: '/monitoring/guides'
    },
    {
      key: 'uncertain',
      title: 'Mutações incertas',
      icon: 'i-lucide-help-circle',
      value: n(summary?.uncertain_results?.mutations_uncertain),
      to: '/monitoring',
      tone: !loading && (summary?.uncertain_results?.mutations_uncertain ?? 0) > 0 ? 'warning' : 'default'
    },
    {
      key: 'coverage_ok',
      title: 'Em dia (cobertura plena)',
      icon: 'i-lucide-shield-check',
      value: n(summary?.fiscal_coverage?.up_to_date_full_only),
      to: '/monitoring',
      tone: 'success'
    },
    {
      key: 'runs_failed',
      title: 'Runs falhos (24h)',
      icon: 'i-lucide-circle-x',
      value: summary?.fiscal_runs?.available === false
        ? (loading ? '…' : '—')
        : n(summary?.fiscal_runs?.failed_24h),
      to: '/monitoring',
      tone: !loading && (summary?.fiscal_runs?.failed_24h ?? 0) > 0 ? 'error' : 'default'
    }
  ]
}

export function buildHomeSerproKpis(
  summary: OperationsSummary | null,
  options?: { loading?: boolean }
): DashboardKpiItem[] {
  const loading = Boolean(options?.loading && !summary)
  const auth = summary?.serpro_authorization
  const powers = summary?.proxy_powers as OperationsProxyPowers | undefined
  const usage = summary?.usage as OperationsUsageSummary | undefined
  const n = (v: number | undefined) => homeDisplayValue(Boolean(options?.loading), !!summary, v)

  const authLabel = !summary
    ? (loading ? '…' : '—')
    : (auth?.configured ? (auth.status || 'CONFIGURADO') : 'NÃO CONFIGURADO')

  return [
    {
      key: 'serpro_auth',
      title: 'Autorização SERPRO',
      icon: 'i-lucide-badge-check',
      value: authLabel,
      to: '/conta/escritorio',
      tone: !summary
        ? 'default'
        : (!auth?.configured || auth.status === 'ACTION_REQUIRED' || auth.status === 'BLOCKED'
            ? 'warning'
            : 'success')
    },
    {
      key: 'proxy_active',
      title: 'Procurações ativas',
      icon: 'i-lucide-file-key',
      value: n(powers?.active),
      to: '/clients'
    },
    {
      key: 'proxy_expiring',
      title: 'Procurações 30d',
      icon: 'i-lucide-timer',
      value: n(powers?.expiring_30d),
      to: '/clients',
      tone: !loading && (powers?.expiring_30d ?? 0) > 0 ? 'warning' : 'default'
    },
    {
      key: 'usage_remaining',
      title: 'Franquia restante',
      icon: 'i-lucide-gauge',
      value: !summary
        ? (loading ? '…' : '—')
        : (usage?.available === false
            ? '—'
            : (usage?.remaining ?? '—')),
      to: validateTenantPath(usage?.deep_link) || '/conta/consumo',
      tone: usage?.alert_threshold_reached ? 'warning' : 'default'
    }
  ]
}

export function buildHomeCommunicationKpis(
  summary: OperationsSummary | null,
  options?: { loading?: boolean }
): DashboardKpiItem[] {
  const loading = Boolean(options?.loading && !summary)
  const comm = summary?.communication as OperationsCommunicationSummary | undefined
  const unavailable = Boolean(summary && comm && comm.available === false)
  const n = (v: number | undefined) => {
    if (unavailable) return '—'
    return homeDisplayValue(Boolean(options?.loading), !!summary, v)
  }

  const connected = comm?.inboxes_by_status?.CONNECTED
  const disconnected = comm?.inboxes_by_status?.DISCONNECTED ?? 0
  const connecting = comm?.inboxes_by_status?.CONNECTING ?? 0
  const attention = disconnected + connecting

  return [
    {
      key: 'wa_connected',
      title: 'Inboxes conectadas',
      icon: 'i-lucide-wifi',
      value: n(connected),
      to: '/communication',
      tone: !loading && !unavailable && (connected ?? 0) === 0 ? 'warning' : 'success'
    },
    {
      key: 'wa_attention',
      title: 'Sessões em atenção',
      icon: 'i-lucide-wifi-off',
      value: n(attention),
      to: '/communication',
      tone: !loading && !unavailable && attention > 0 ? 'warning' : 'default',
      critical: !loading && !unavailable && attention > 0
    },
    {
      key: 'wa_open',
      title: 'Conversas abertas',
      icon: 'i-lucide-messages-square',
      value: n(comm?.conversations_open),
      to: '/communication'
    },
    {
      key: 'wa_pending',
      title: 'Conversas pendentes',
      icon: 'i-lucide-message-circle-warning',
      value: n(comm?.conversations_pending),
      to: '/communication',
      tone: !loading && !unavailable && (comm?.conversations_pending ?? 0) > 0 ? 'warning' : 'default'
    },
    {
      key: 'wa_outbox_dead',
      title: 'Outbox mortas',
      icon: 'i-lucide-skull',
      value: n(comm?.outbox_dead),
      to: '/communication',
      tone: !loading && !unavailable && (comm?.outbox_dead ?? 0) > 0 ? 'error' : 'default',
      critical: !loading && !unavailable && (comm?.outbox_dead ?? 0) > 0
    },
    {
      key: 'wa_outbox_retry',
      title: 'Outbox em retry',
      icon: 'i-lucide-refresh-cw',
      value: n(comm?.outbox_retry),
      to: '/communication',
      tone: !loading && !unavailable && (comm?.outbox_retry ?? 0) > 0 ? 'warning' : 'default'
    }
  ]
}

export function homePlatformHealthLabel(health: OperationsPlatformHealth | undefined | null): string {
  if (!health) return '—'
  if (!health.available) return health.status || 'INDISPONÍVEL'
  if (health.kill_switch) return 'KILL SWITCH'
  if (health.circuit_open) return 'CIRCUIT OPEN'
  return health.status || 'OK'
}
