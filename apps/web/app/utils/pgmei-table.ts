import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h } from 'vue'
/**
 * Imports estáticos — NÃO usar resolveComponent aqui.
 * Builders em computed durante o render da UTable perdem o instance da página
 * e as células ficam vazias. @see table-sort.ts / pgdasd-table.ts
 */
import {
  FiscalClientCell
} from '#components'
import UBadge from '@nuxt/ui/components/Badge.vue'
import UButton from '@nuxt/ui/components/Button.vue'
import UIcon from '@nuxt/ui/components/Icon.vue'
import UTooltip from '@nuxt/ui/components/Tooltip.vue'
import type { SimplesMeiClientRow } from '~/types/fiscal-modules'
import { formatDate, formatDateTime } from '~/utils/format'
import { pgdasdCanRequestAutomatic, pgdasdTrackingMeta } from '~/utils/pgdasd'
import {
  pgmeiDebtMeta,
  pgmeiDebtTooltip,
  pgmeiFreshnessState,
  pgmeiSummary
} from '~/utils/pgmei'
import { tableCellBadgeProps } from '~/utils/table-ui'
import { sortHeader } from '~/utils/table-sort'
import {
  buildMonitoringActionsMenuCell,
  buildMonitoringComunicacaoColumn,
  MONITORING_ACTIONS_LABEL,
  MONITORING_ACTIONS_META,
  MONITORING_CLIENT_COLUMN_META,
  MONITORING_CONSULTED_ID,
  MONITORING_CONSULTED_LABEL,
  MONITORING_CONSULTED_META,
  type MonitoringSendColumnState
} from '~/utils/monitoring-table-columns'
import { simplesMeiMissingProcuracaoSituation } from '~/utils/simples-mei-situation'
import { consultPendingSkeleton } from '~/utils/consult-pending-skeleton'

/**
 * Renderer PGMEI — spine canônica default:
 * Situação · Cliente · Comunicação · Consulta · Ações.
 * Histórico fiscal só no menu Ações (não entra na grade).
 */
export function buildPgmeiColumns(options: {
  year: number
  onHistory: (row: SimplesMeiClientRow) => void
  onConsult: (row: SimplesMeiClientRow) => void
  onTracking: (row: SimplesMeiClientRow) => void
  onConfigure: (row: SimplesMeiClientRow) => void
  onPublicServices: (row: SimplesMeiClientRow) => void
  onSend: (row: SimplesMeiClientRow) => void
  onToggleAutomatic: (row: SimplesMeiClientRow, value: boolean) => void
  onEditClient?: (row: SimplesMeiClientRow) => void
  onExclude?: (row: SimplesMeiClientRow) => void
  canConsult?: boolean
  pendingClientIds?: ReadonlySet<number>
  sendBusyClientIds?: ReadonlySet<number>
  toggleBusyClientIds?: ReadonlySet<number>
}): TableColumn<SimplesMeiClientRow>[] {
  function isRowPending(clientId: number): boolean {
    return options.pendingClientIds?.has(clientId) === true
  }

  function actionItems(row: SimplesMeiClientRow): DropdownMenuItem[][] {
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
    }, {
      label: 'Consultas CCMEI e DASN-SIMEI',
      icon: 'i-lucide-badge-check',
      onSelect: () => options.onPublicServices(row)
    }, {
      label: `Ver histórico fiscal PGMEI de ${options.year}`,
      icon: 'i-lucide-history',
      onSelect: () => options.onHistory(row)
    })
    if (options.onExclude) {
      items.push({
        label: 'Excluir do monitoramento',
        icon: 'i-lucide-user-minus',
        onSelect: () => options.onExclude?.(row)
      })
    }
    return [items]
  }

  function sendState(row: SimplesMeiClientRow): MonitoringSendColumnState {
    const summary = pgmeiSummary(row, options.year)
    const communication = summary?.communication
    const tracking = pgdasdTrackingMeta(communication?.tracking_status)
    return {
      trackingIcon: tracking.icon,
      trackingLabel: `Histórico local de comunicação: ${tracking.label}`,
      trackingColor: tracking.color,
      automaticRequested: communication?.automatic_requested === true,
      canToggleAutomatic: pgdasdCanRequestAutomatic(communication),
      canSend: communication?.can_send === true,
      sendBusy: options.sendBusyClientIds?.has(row.client_id) === true,
      toggleBusy: options.toggleBusyClientIds?.has(row.client_id) === true
    }
  }

  return [
    {
      id: 'situation',
      header: 'Situação',
      enableSorting: false,
      meta: { class: { th: 'w-36 min-w-28', td: 'w-36 min-w-28' } },
      cell: ({ row }) => {
        if (isRowPending(row.original.client_id)) {
          return consultPendingSkeleton('pgmei-situation-pending')
        }
        const missing = simplesMeiMissingProcuracaoSituation(row.original, 'pgmei-situation')
        if (missing) {
          return h(UTooltip, { text: missing.tooltip }, {
            default: () => h('div', {
              'class': 'flex w-full min-w-0 items-center gap-1',
              'aria-label': `Situação PGMEI: ${missing.label}`
            }, [
              h(UBadge, tableCellBadgeProps({
                'label': missing.label,
                'color': missing.color,
                'icon': missing.icon,
                'class': 'min-w-0 flex-1',
                'data-testid': missing.testId
              }))
            ])
          })
        }
        const summary = pgmeiSummary(row.original, options.year)
        const debt = pgmeiDebtMeta(summary?.debt_state)
        const outdated = summary?.debt_state !== 'UNVERIFIED'
          && Boolean(summary?.last_valid_query_at)
          && pgmeiFreshnessState(summary?.freshness_state) === 'OUTDATED'
        return h(UTooltip, { text: pgmeiDebtTooltip(summary, options.year) }, {
          default: () => h('div', {
            'class': 'flex w-full min-w-0 items-center gap-1',
            'aria-label': `Situação PGMEI: ${debt.label}`
          }, [
            h(UBadge, tableCellBadgeProps({
              'label': debt.label,
              'color': debt.color,
              'icon': debt.icon,
              'class': 'min-w-0 flex-1',
              'data-testid': 'pgmei-situation'
            })),
            outdated
              ? h(UIcon, {
                  'name': 'i-lucide-clock-alert',
                  'class': 'size-3.5 shrink-0 text-warning',
                  'aria-label': 'Consulta desatualizada'
                })
              : null
          ])
        })
      }
    },
    {
      id: 'client',
      header: ({ column }) => sortHeader('Cliente', column),
      enableHiding: false,
      meta: { ...MONITORING_CLIENT_COLUMN_META },
      cell: ({ row }) => h(FiscalClientCell, {
        clientId: row.original.client_id,
        name: row.original.legal_name,
        legalName: row.original.legal_name,
        cnpj: row.original.cnpj,
        cnpjMasked: row.original.cnpj_masked,
        to: `/monitoring/clients/${row.original.client_id}`
      })
    },
    buildMonitoringComunicacaoColumn<SimplesMeiClientRow>({
      getState: row => sendState(row),
      onTracking: row => options.onTracking(row),
      onSend: row => options.onSend(row),
      onToggleAutomatic: (row, value) => options.onToggleAutomatic(row, value),
      testIdPrefix: 'pgmei-tracking'
    }),
    {
      id: MONITORING_CONSULTED_ID,
      header: ({ column }) => sortHeader(MONITORING_CONSULTED_LABEL, column),
      meta: { ...MONITORING_CONSULTED_META },
      cell: ({ row }) => {
        if (isRowPending(row.original.client_id)) {
          return consultPendingSkeleton('pgmei-consult-pending', 'max-w-[5.5rem]')
        }
        const lastQuery = pgmeiSummary(row.original, options.year)?.last_valid_query_at
        const dateNode = h(UTooltip, {
          text: lastQuery
            ? `Última consulta válida: ${formatDateTime(lastQuery)}`
            : `Nenhuma consulta produtiva válida para ${options.year}`
        }, {
          default: () => h('span', {
            'class': 'whitespace-nowrap tabular-nums text-xs',
            'data-testid': 'pgmei-last-query'
          }, formatDate(lastQuery))
        })
        if (options.canConsult === false) {
          return dateNode
        }
        return h('div', { class: 'flex items-center gap-0.5' }, [
          dateNode,
          h(UTooltip, { text: 'Consultar dívida PGMEI deste cliente' }, {
            default: () => h(UButton, {
              'size': 'xs',
              'color': 'primary',
              'variant': 'ghost',
              'icon': 'i-lucide-refresh-cw',
              'aria-label': 'Consultar dívida PGMEI',
              'data-testid': 'pgmei-row-consult',
              'onClick': () => options.onConsult(row.original)
            })
          })
        ])
      }
    },
    {
      id: 'actions',
      header: MONITORING_ACTIONS_LABEL,
      enableSorting: false,
      meta: { ...MONITORING_ACTIONS_META },
      cell: ({ row }) => {
        const name = row.original.legal_name || `cliente ${row.original.client_id}`
        return buildMonitoringActionsMenuCell({
          ariaLabel: `Mais ações de ${name}`,
          testId: 'pgmei-row-actions',
          items: actionItems(row.original)
        })
      }
    }
  ]
}
