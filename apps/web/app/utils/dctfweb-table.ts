import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h } from 'vue'
/**
 * Imports estáticos — NÃO usar resolveComponent aqui.
 * Builders em computed durante o render da UTable perdem o instance da página
 * e as células ficam vazias. @see table-sort.ts / pgdasd-table.ts
 */
import {
  FiscalClientCell,
  FiscalStatusBadge
} from '#components'
import UBadge from '@nuxt/ui/components/Badge.vue'
import UTooltip from '@nuxt/ui/components/Tooltip.vue'
import type {
  DctfwebClientRow
} from '~/types/fiscal-modules'
import {
  dctfwebCanRequestAutomatic,
  dctfwebDeclarationMeta,
  dctfwebLastDeclarationLabel,
  dctfwebSummary,
  dctfwebTrackingMeta,
  formatDctfwebDate
} from '~/utils/dctfweb'
import { sortHeader } from '~/utils/table-sort'
import { tableCellBadgeProps } from '~/utils/table-ui'
import {
  buildMonitoringActionsMenuCell,
  buildMonitoringConsultedColumn,
  buildMonitoringComunicacaoColumn,
  MONITORING_ACTIONS_LABEL,
  MONITORING_ACTIONS_META,
  MONITORING_CLIENT_COLUMN_META,
  MONITORING_CONSULTED_ID,
  MONITORING_CONSULTED_LABEL,
  MONITORING_CONSULTED_META,
  type MonitoringSendColumnState
} from '~/utils/monitoring-table-columns'

/**
 * Renderer DCTFWeb — spine canônica com declaração:
 * Situação · Últ. Declaração · Cliente · Comunicação · Consulta · Ações.
 * Histórico de busca / documentos locais só no menu Ações.
 */
export function buildDctfwebColumns(options: {
  onHistory: (row: DctfwebClientRow) => void
  onTracking: (row: DctfwebClientRow) => void
  onConfigure: (row: DctfwebClientRow) => void
  onSend: (row: DctfwebClientRow) => void
  onToggleAutomatic: (row: DctfwebClientRow, value: boolean) => void
  onEditClient?: (row: DctfwebClientRow) => void
  onExclude?: (row: DctfwebClientRow) => void
  sendBusyClientIds?: ReadonlySet<number>
  toggleBusyClientIds?: ReadonlySet<number>
}): TableColumn<DctfwebClientRow>[] {
  function actionItems(row: DctfwebClientRow): DropdownMenuItem[][] {
    const summary = dctfwebSummary(row)
    const name = row.name || row.legal_name || `cliente ${row.client_id}`
    const items: DropdownMenuItem[] = [
      {
        label: 'Abrir cliente',
        icon: 'i-lucide-building-2',
        to: `/monitoring/clients/${row.client_id}`
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
      label: 'Preferências de comunicação',
      icon: 'i-lucide-settings-2',
      onSelect: () => options.onConfigure(row)
    })
    if (summary?.has_history) {
      items.push({
        label: 'Histórico de busca',
        icon: 'i-lucide-history',
        onSelect: () => options.onHistory(row)
      })
    }
    if (options.onExclude) {
      items.push({
        label: 'Excluir do monitoramento',
        icon: 'i-lucide-user-minus',
        onSelect: () => options.onExclude?.(row)
      })
    }
    return [items, [{
      label: `Documentos locais de ${name}`,
      icon: 'i-lucide-file-text',
      disabled: !summary?.has_history,
      onSelect: () => options.onHistory(row)
    }]]
  }

  function sendState(row: DctfwebClientRow): MonitoringSendColumnState {
    const summary = dctfwebSummary(row)
    const communication = summary?.communication
    const hasTracking = summary?.has_tracking === true
      || Boolean(communication?.tracking_status
        && communication.tracking_status !== 'NO_HISTORY'
        && communication.tracking_status !== 'NOT_CONFIGURED')
    const tracking = dctfwebTrackingMeta(communication?.tracking_status)
    return {
      trackingIcon: tracking.icon,
      trackingLabel: hasTracking ? `Histórico local: ${tracking.label}` : 'Sem histórico local',
      trackingColor: hasTracking ? tracking.color : 'neutral',
      trackingDisabled: !hasTracking && !communication,
      automaticRequested: communication?.automatic_requested === true,
      canToggleAutomatic: dctfwebCanRequestAutomatic(communication),
      canSend: communication?.can_send === true,
      sendBusy: options.sendBusyClientIds?.has(row.client_id) === true,
      toggleBusy: options.toggleBusyClientIds?.has(row.client_id) === true
    }
  }

  return [
    {
      id: 'situation',
      header: ({ column }) => sortHeader('Situação', column),
      enableHiding: false,
      meta: { class: { th: 'min-w-36', td: 'min-w-36' } },
      cell: ({ row }) => {
        const summary = dctfwebSummary(row.original)
        const state = summary?.declaration_state
        if (!state) {
          return h('div', { class: 'block w-full min-w-0' }, [
            h(UBadge, tableCellBadgeProps({
              'label': '–',
              'color': 'neutral',
              'data-testid': 'dctfweb-situation-empty'
            }))
          ])
        }
        const meta = dctfwebDeclarationMeta(state)
        return h(UTooltip, { text: meta.description }, {
          default: () => h('div', { class: 'block w-full min-w-0' }, [
            h(UBadge, tableCellBadgeProps({
              'label': meta.label,
              'color': meta.color,
              'icon': meta.icon,
              'data-testid': 'dctfweb-situation'
            }))
          ])
        })
      }
    },
    {
      id: 'last_declaration',
      header: 'Últ. Declaração',
      enableSorting: false,
      meta: { class: { th: 'min-w-28', td: 'min-w-28' } },
      cell: ({ row }) => {
        const summary = dctfwebSummary(row.original)
        const label = dctfwebLastDeclarationLabel(summary)
        if (label === '—') {
          return h('div', { class: 'block w-full min-w-0' }, [
            h(UBadge, tableCellBadgeProps({
              'label': '–',
              'color': 'neutral',
              'data-testid': 'dctfweb-last-declaration-empty'
            }))
          ])
        }
        return h('div', { class: 'block w-full min-w-0' }, [
          h(UBadge, tableCellBadgeProps({
            'label': label,
            'color': 'primary',
            'data-testid': 'dctfweb-last-declaration'
          }))
        ])
      }
    },
    {
      id: 'client',
      header: ({ column }) => sortHeader('Cliente', column),
      enableHiding: false,
      meta: { ...MONITORING_CLIENT_COLUMN_META },
      cell: ({ row }) => h(FiscalClientCell, {
        clientId: row.original.client_id,
        name: row.original.legal_name || row.original.name,
        legalName: row.original.legal_name,
        cnpj: row.original.cnpj,
        cnpjMasked: row.original.cnpj_masked,
        to: `/monitoring/clients/${row.original.client_id}`
      })
    },
    buildMonitoringComunicacaoColumn<DctfwebClientRow>({
      getState: row => sendState(row),
      onTracking: row => options.onTracking(row),
      onSend: row => options.onSend(row),
      onToggleAutomatic: (row, value) => options.onToggleAutomatic(row, value),
      testIdPrefix: 'dctfweb-tracking'
    }),
    {
      id: MONITORING_CONSULTED_ID,
      header: ({ column }) => sortHeader(MONITORING_CONSULTED_LABEL, column),
      meta: { ...MONITORING_CONSULTED_META },
      cell: ({ row }) => {
        const summary = dctfwebSummary(row.original)
        const label = formatDctfwebDate(
          summary?.last_search_at || summary?.last_valid_query_at
        )
        return h('span', {
          'class': 'whitespace-nowrap tabular-nums text-xs text-muted',
          'data-testid': 'dctfweb-last-search'
        }, label)
      }
    },
    {
      id: 'actions',
      header: MONITORING_ACTIONS_LABEL,
      enableHiding: false,
      enableSorting: false,
      meta: { ...MONITORING_ACTIONS_META },
      cell: ({ row }) => {
        const name = row.original.name || row.original.legal_name || `cliente ${row.original.client_id}`
        return buildMonitoringActionsMenuCell({
          ariaLabel: `Mais ações de ${name}`,
          testId: 'dctfweb-row-actions',
          items: actionItems(row.original)
        })
      }
    }
  ]
}

/**
 * Renderer MIT independente — não reutiliza colunas DCTFWeb.
 * Spine: Situação · Cliente · domínio · Comunicação · Consulta · Ações.
 * Pipeline de comunicação MIT pode ficar fail-closed até enrichment existir.
 */
export function buildMitColumns(options: {
  onOpenClient: (row: DctfwebClientRow) => void
  onListApuracoes: (row: DctfwebClientRow) => void
  onEditClient?: (row: DctfwebClientRow) => void
  onTracking?: (row: DctfwebClientRow) => void
  onSend?: (row: DctfwebClientRow) => void
  onToggleAutomatic?: (row: DctfwebClientRow, value: boolean) => void
}): TableColumn<DctfwebClientRow>[] {
  function actionItems(row: DctfwebClientRow): DropdownMenuItem[][] {
    const items: DropdownMenuItem[] = [
      {
        label: 'Abrir cliente',
        icon: 'i-lucide-building-2',
        onSelect: () => options.onOpenClient(row)
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
      label: 'Ver apurações MIT 317 locais',
      icon: 'i-lucide-list-filter',
      onSelect: () => options.onListApuracoes(row)
    })
    return [items]
  }

  function sendState(row: DctfwebClientRow): MonitoringSendColumnState {
    const communication = row.detail?.mit?.communication
    const tracking = dctfwebTrackingMeta(communication?.tracking_status)
    return {
      trackingIcon: tracking.icon,
      trackingLabel: communication ? tracking.label : 'Sem histórico local',
      trackingColor: communication ? tracking.color : 'neutral',
      trackingDisabled: !communication || !options.onTracking,
      automaticRequested: communication?.automatic_requested === true,
      canToggleAutomatic: false,
      canSend: communication?.can_send === true && Boolean(options.onSend)
    }
  }

  return [
    {
      id: 'situation',
      header: 'Situação',
      cell: ({ row }) => h(FiscalStatusBadge, { fill: true, status: row.original.detail?.mit?.situation || row.original.situation })
    },
    {
      id: 'client',
      header: ({ column }) => sortHeader('Cliente', column),
      enableHiding: false,
      meta: { ...MONITORING_CLIENT_COLUMN_META },
      cell: ({ row }) => h(FiscalClientCell, {
        clientId: row.original.client_id,
        name: row.original.legal_name || row.original.name,
        legalName: row.original.legal_name,
        cnpj: row.original.cnpj,
        cnpjMasked: row.original.cnpj_masked,
        to: `/monitoring/clients/${row.original.client_id}`
      })
    },
    {
      id: 'period',
      header: 'Competência',
      enableSorting: false,
      cell: ({ row }) => String(row.original.detail?.mit?.period_key || row.original.competence || '—')
    },
    {
      id: 'closure',
      header: 'Encerramento',
      enableSorting: false,
      cell: ({ row }) => {
        const status = row.original.detail?.mit?.encerramento_status
        if (!status) return '—'
        return h(FiscalStatusBadge, { fill: true, status })
      }
    },
    buildMonitoringComunicacaoColumn<DctfwebClientRow>({
      getState: row => sendState(row),
      onTracking: row => options.onTracking?.(row),
      onSend: row => options.onSend?.(row),
      onToggleAutomatic: (row, value) => options.onToggleAutomatic?.(row, value),
      testIdPrefix: 'mit-tracking'
    }),
    buildMonitoringConsultedColumn<DctfwebClientRow>({
      getAt: row => row.last_consulted_at || row.last_snapshot_at,
      testId: 'mit-last-consulted'
    }),
    {
      id: 'actions',
      header: MONITORING_ACTIONS_LABEL,
      enableHiding: false,
      enableSorting: false,
      meta: { ...MONITORING_ACTIONS_META },
      cell: ({ row }) => {
        const name = row.original.name || row.original.legal_name || `cliente ${row.original.client_id}`
        return buildMonitoringActionsMenuCell({
          ariaLabel: `Mais ações de ${name}`,
          testId: 'mit-row-actions',
          items: actionItems(row.original)
        })
      }
    }
  ]
}
