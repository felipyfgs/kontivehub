import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h } from 'vue'
/**
 * Imports estáticos — NÃO usar resolveComponent aqui.
 * Builders em computed durante o render da UTable perdem o instance da página
 * e as células ficam vazias. @see table-sort.ts / pgdasd-table.ts
 */
import {
  ClientsClientProcuracaoBadge,
  FiscalClientCell,
  FiscalCoverageBadge,
  FiscalStatusBadge,
  MonitoringCommercialMetaCell
} from '#components'
import UBadge from '@nuxt/ui/components/Badge.vue'
import UTooltip from '@nuxt/ui/components/Tooltip.vue'
import type { SitfisClientDetail, SitfisClientRow } from '~/types/fiscal-modules'
import { documentActionVisible } from '~/types/fiscal-modules'
import { sortHeader } from '~/utils/table-sort'
import { pgdasdCanRequestAutomatic, pgdasdTrackingMeta } from '~/utils/pgdasd'
import {
  buildMonitoringActionsMenuCell,
  buildMonitoringConsultedColumn,
  buildMonitoringComunicacaoColumn,
  MONITORING_ACTIONS_LABEL,
  MONITORING_ACTIONS_META,
  MONITORING_CLIENT_COLUMN_META,
  type MonitoringSendColumnState
} from '~/utils/monitoring-table-columns'

/**
 * Renderer SITFIS — spine canônica:
 * Situação · Cliente · Achados · Cobertura · Procuração · Franquia / agenda ·
 * Idade / TTL · Comunicação · Consulta · Ações.
 * Secundárias (procuração/franquia/idade) começam ocultas via Exibir.
 */
export function sitfisDetailOf(row: SitfisClientRow): SitfisClientDetail {
  return row.detail || {}
}

export function sitfisAgeLabel(seconds?: number | null) {
  if (seconds == null || !Number.isFinite(Number(seconds))) return '—'
  const s = Number(seconds)
  if (s < 60) return `${s}s`
  if (s < 3600) return `${Math.floor(s / 60)} min`
  if (s < 86400) return `${Math.floor(s / 3600)} h`
  return `${Math.floor(s / 86400)} d`
}

export function buildSitfisColumns(options: {
  allowsDocument: boolean
  onFindings: (row: SitfisClientRow) => void
  onTracking: (row: SitfisClientRow) => void
  onSend: (row: SitfisClientRow) => void
  onToggleAutomatic: (row: SitfisClientRow, value: boolean) => void
  onDocument: (row: SitfisClientRow) => void
  onEditClient?: (row: SitfisClientRow) => void
}): TableColumn<SitfisClientRow>[] {
  function actionItems(row: SitfisClientRow): DropdownMenuItem[][] {
    const href = documentActionVisible(row.document)
      ? row.document?.href?.trim() || null
      : null
    const docEnabled = options.allowsDocument && Boolean(href)
    const items: DropdownMenuItem[] = [
      {
        label: 'Abrir cliente',
        icon: 'i-lucide-building-2',
        to: `/monitoring/clients/${row.client_id}/sitfis`
      }
    ]
    if (options.onEditClient) {
      items.push({
        label: 'Editar cliente',
        icon: 'i-lucide-pencil',
        onSelect: () => options.onEditClient?.(row)
      })
    }
    items.push({
      label: 'Ver achados e pendências',
      icon: 'i-lucide-list-checks',
      onSelect: () => options.onFindings(row)
    })
    items.push({
      label: 'Histórico de busca',
      icon: 'i-lucide-history',
      to: `/monitoring/clients/${row.client_id}/sitfis`
    })
    if (docEnabled && href) {
      items.push({
        label: row.document?.label || 'Baixar documento oficial',
        icon: 'i-lucide-file-down',
        onSelect: () => options.onDocument(row)
      })
    }
    return [items]
  }

  function sendState(row: SitfisClientRow): MonitoringSendColumnState {
    const communication = sitfisDetailOf(row).communication
    const tracking = pgdasdTrackingMeta(communication?.tracking_status)
    return {
      trackingIcon: tracking.icon,
      trackingLabel: communication ? tracking.label : 'Sem histórico local',
      trackingColor: communication ? tracking.color : 'neutral',
      trackingDisabled: !communication,
      automaticRequested: communication?.automatic_requested === true,
      canToggleAutomatic: pgdasdCanRequestAutomatic(communication),
      canSend: communication?.can_send === true
    }
  }

  return [
    {
      id: 'situation',
      header: ({ column }) => sortHeader('Situação', column),
      meta: { class: { th: 'w-28 min-w-24', td: 'w-28 min-w-24' } },
      cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: row.original.situation })
    },
    {
      id: 'client',
      header: ({ column }) => sortHeader('Cliente', column),
      enableHiding: false,
      meta: { ...MONITORING_CLIENT_COLUMN_META },
      cell: ({ row }) => h(FiscalClientCell, {
        clientId: row.original.client_id,
        name: row.original.legal_name || row.original.name || row.original.display_name,
        legalName: row.original.legal_name,
        cnpj: row.original.cnpj,
        cnpjMasked: row.original.cnpj_masked,
        to: `/monitoring/clients/${row.original.client_id}/sitfis`
      })
    },
    {
      id: 'findings',
      header: 'Achados',
      enableSorting: false,
      meta: { class: { th: 'w-24 min-w-20', td: 'w-24 min-w-20' } },
      cell: ({ row }) => {
        const d = sitfisDetailOf(row.original)
        const findings = d.findings_count ?? 0
        const pending = d.pending_count ?? 0
        return h(UTooltip, {
          text: `${findings} achados · ${pending} pendências`
        }, {
          default: () => h('span', {
            'class': 'text-xs tabular-nums whitespace-nowrap',
            'data-testid': 'sitfis-findings-count'
          }, `${findings} · ${pending} pend.`)
        })
      }
    },
    {
      id: 'coverage',
      header: 'Cobertura',
      enableSorting: false,
      meta: { class: { th: 'w-36 min-w-28', td: 'w-36 min-w-28' } },
      cell: ({ row }) => h(FiscalCoverageBadge, { fill: true, coverage: row.original.coverage })
    },
    {
      id: 'procuracao',
      header: 'Procuração',
      enableSorting: false,
      meta: { class: { th: 'w-16 min-w-14', td: 'w-16 min-w-14' } },
      cell: ({ row }) => h(ClientsClientProcuracaoBadge, {
        status: row.original.procuracao_status,
        showHint: false,
        compact: true
      })
    },
    {
      id: 'franchise',
      header: 'Franquia / agenda',
      enableSorting: false,
      meta: { class: { th: 'w-40 min-w-36', td: 'w-40 min-w-36' } },
      cell: ({ row }) => h(MonitoringCommercialMetaCell, {
        remaining: row.original.commercial_quota?.remaining,
        limit: row.original.commercial_quota?.limit,
        used: row.original.commercial_quota?.used,
        blockReason: row.original.block_reason || row.original.commercial_quota?.block_reason,
        blockMessage: row.original.block_message,
        lastSnapshotAt: row.original.last_snapshot_at || row.original.last_consulted_at,
        nextScheduledAt: row.original.next_scheduled_at,
        isRecent: row.original.is_recent_snapshot
      })
    },
    {
      id: 'age',
      header: 'Idade / TTL',
      enableSorting: false,
      meta: { class: { th: 'w-24 min-w-20', td: 'w-24 min-w-20' } },
      cell: ({ row }) => {
        const d = sitfisDetailOf(row.original)
        const age = sitfisAgeLabel(d.age_seconds)
        const ttl = d.ttl_seconds != null ? sitfisAgeLabel(d.ttl_seconds) : '—'
        return h('span', { class: 'inline-flex items-center gap-1 text-xs tabular-nums' }, [
          age,
          h('span', { class: 'text-muted' }, `/ ${ttl}`),
          d.is_expired === true
            ? h(UBadge, { color: 'warning', variant: 'subtle', size: 'xs' }, () => 'Expirado')
            : null
        ])
      }
    },
    buildMonitoringComunicacaoColumn<SitfisClientRow>({
      getState: row => sendState(row),
      onTracking: row => options.onTracking(row),
      onSend: row => options.onSend(row),
      onToggleAutomatic: (row, value) => options.onToggleAutomatic(row, value),
      testIdPrefix: 'sitfis-tracking'
    }),
    buildMonitoringConsultedColumn<SitfisClientRow>({
      getAt: (row) => {
        return sitfisDetailOf(row).observed_at
          || row.last_snapshot_at
          || row.last_consulted_at
      },
      testId: 'sitfis-observed'
    }),
    {
      id: 'actions',
      header: MONITORING_ACTIONS_LABEL,
      enableHiding: false,
      enableSorting: false,
      meta: { ...MONITORING_ACTIONS_META },
      cell: ({ row }) => {
        const name = row.original.legal_name || row.original.name || `cliente ${row.original.client_id}`
        return buildMonitoringActionsMenuCell({
          ariaLabel: `Mais ações de ${name}`,
          testId: 'sitfis-row-actions',
          items: actionItems(row.original)
        })
      }
    }
  ]
}
