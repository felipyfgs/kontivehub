import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h } from 'vue'
/**
 * Imports estáticos — NÃO usar resolveComponent aqui.
 * Estes builders rodam em computed durante o render da UTable; getCurrentInstance()
 * não é a página e resolveComponent falha → células vazias com 1 registro no rodapé.
 * @see table-sort.ts (mesmo padrão com #components)
 */
import {
  FiscalClientCell,
  MonitoringPgdasdDeclarationIndicator,
  MonitoringPgdasdPaymentValue,
  MonitoringPgdasdRbt12Value
} from '#components'
import UBadge from '@nuxt/ui/components/Badge.vue'
import UButton from '@nuxt/ui/components/Button.vue'
import UTooltip from '@nuxt/ui/components/Tooltip.vue'
import type { SimplesMeiClientRow } from '~/types/fiscal-modules'
import { formatDate, formatDateTime } from '~/utils/format'
import {
  pgdasdCanRequestAutomatic,
  pgdasdDeclarationMeta,
  pgdasdDeclarationPeriod,
  pgdasdSummary,
  pgdasdTrackingMeta
} from '~/utils/pgdasd'
import { sortHeader } from '~/utils/table-sort'
import { tableCellBadgeProps } from '~/utils/table-ui'
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
 * Renderer PGDAS-D — exceção da spine canônica:
 * Situação (pagamento) · Declaração (entrega) · RBT12 · Cliente · Comunicação · Consulta · Ações.
 * Seleção é acrescentada pelo shell autorizado antes de Situação.
 * Histórico de busca só no menu Ações (não entra na grade).
 */
export function buildPgdasdColumns(options: {
  onHistory: (row: SimplesMeiClientRow) => void
  onTracking: (row: SimplesMeiClientRow) => void
  onConfigure: (row: SimplesMeiClientRow) => void
  onSend: (row: SimplesMeiClientRow) => void
  onToggleAutomatic: (row: SimplesMeiClientRow, value: boolean) => void
  onEditClient?: (row: SimplesMeiClientRow) => void
  onConsult?: (row: SimplesMeiClientRow) => void
  onExclude?: (row: SimplesMeiClientRow) => void
  canConsult?: boolean
  /** client_id com consulta enfileirada aguardando resultado. */
  pendingClientIds?: ReadonlySet<number>
  sendBusyClientIds?: ReadonlySet<number>
  toggleBusyClientIds?: ReadonlySet<number>
}): TableColumn<SimplesMeiClientRow>[] {
  function isRowPending(clientId: number): boolean {
    return options.pendingClientIds?.has(clientId) === true
  }
  const DeclarationIndicator = MonitoringPgdasdDeclarationIndicator
  const Rbt12Value = MonitoringPgdasdRbt12Value
  const PaymentValue = MonitoringPgdasdPaymentValue

  function declarationTooltip(row: SimplesMeiClientRow): string {
    const summary = pgdasdSummary(row)
    const meta = pgdasdDeclarationMeta(summary?.declaration_state)
    const period = pgdasdDeclarationPeriod(summary)
    const reason = summary?.declaration_state_reason || summary?.declaration_reason
    const lastQuery = summary?.last_valid_query_at
    return [
      meta.label,
      period !== '—' ? `PA: ${period}.` : null,
      reason?.trim() || meta.description,
      lastQuery
        ? `Última consulta válida: ${formatDateTime(lastQuery)}.`
        : 'Nenhuma consulta produtiva válida.'
    ].filter(Boolean).join(' ')
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
      label: 'Ver histórico fiscal PGDAS-D',
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
    const summary = pgdasdSummary(row)
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
      header: ({ column }) => h(UTooltip, {
        text: 'Pagamento dos DAS do período de apuração esperado.'
      }, {
        default: () => sortHeader('Situação', column)
      }),
      meta: { class: { th: 'w-28 min-w-24', td: 'w-28 min-w-24' } },
      cell: ({ row }) => {
        if (isRowPending(row.original.client_id)) {
          return consultPendingSkeleton('pgdasd-situation-pending')
        }
        const missing = simplesMeiMissingProcuracaoSituation(row.original, 'pgdasd-situation')
        if (missing) {
          return h(UTooltip, { text: missing.tooltip }, {
            default: () => h('div', { class: 'block w-full min-w-0' }, [
              h(UBadge, tableCellBadgeProps({
                'label': missing.label,
                'color': missing.color,
                'icon': missing.icon,
                'aria-label': `Situação PGDAS-D: ${missing.label}`,
                'data-testid': missing.testId
              }))
            ])
          })
        }
        return h('div', { class: 'block w-full min-w-0' }, [
          h(PaymentValue, {
            summary: pgdasdSummary(row.original)
          })
        ])
      }
    },
    {
      id: 'last_declaration',
      header: ({ column }) => sortHeader('Declaração', column),
      meta: { class: { th: 'w-24 min-w-20', td: 'w-24 min-w-20' } },
      cell: ({ row }) => {
        if (isRowPending(row.original.client_id)) {
          return consultPendingSkeleton('pgdasd-declaration-pending', 'max-w-[4.5rem]')
        }
        const summary = pgdasdSummary(row.original)
        return h(DeclarationIndicator, {
          period: pgdasdDeclarationPeriod(summary),
          state: summary?.declaration_state,
          reason: summary?.declaration_state_reason || summary?.declaration_reason,
          tooltipText: declarationTooltip(row.original)
        })
      }
    },
    {
      id: 'rbt12',
      header: ({ column }) => h(UTooltip, {
        text: 'RBT12 (RB12): receita bruta acumulada nos 12 meses anteriores ao período de apuração. Não é RPA (receita do mês) nem o sublimite anual.'
      }, {
        default: () => sortHeader('RBT12', column)
      }),
      meta: { class: { th: 'w-0 whitespace-nowrap', td: 'w-0 whitespace-nowrap' } },
      cell: ({ row }) => {
        if (isRowPending(row.original.client_id)) {
          return consultPendingSkeleton('pgdasd-rbt12-pending', 'max-w-[5rem]')
        }
        return h(Rbt12Value, {
          rbt12: pgdasdSummary(row.original)?.rbt12
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
      testIdPrefix: 'pgdasd-tracking'
    }),
    {
      id: MONITORING_CONSULTED_ID,
      header: ({ column }) => sortHeader(MONITORING_CONSULTED_LABEL, column),
      meta: { ...MONITORING_CONSULTED_META },
      cell: ({ row }) => {
        if (isRowPending(row.original.client_id)) {
          return consultPendingSkeleton('pgdasd-consult-pending', 'max-w-[5.5rem]')
        }
        const lastQuery = pgdasdSummary(row.original)?.last_valid_query_at
        const dateNode = h(UTooltip, {
          text: lastQuery
            ? `Última consulta válida: ${formatDateTime(lastQuery)}`
            : 'Nenhuma consulta produtiva válida'
        }, {
          default: () => h('span', {
            'class': 'whitespace-nowrap tabular-nums text-xs',
            'data-testid': 'pgdasd-last-query'
          }, formatDate(lastQuery))
        })
        if (!options.canConsult || !options.onConsult) {
          return dateNode
        }
        return h('div', { class: 'flex items-center gap-0.5' }, [
          dateNode,
          h(UTooltip, { text: 'Consultar PGDAS-D deste cliente' }, {
            default: () => h(UButton, {
              'size': 'xs',
              'color': 'primary',
              'variant': 'ghost',
              'icon': 'i-lucide-refresh-cw',
              'aria-label': 'Consultar PGDAS-D',
              'data-testid': 'pgdasd-row-consult',
              'onClick': () => options.onConsult?.(row.original)
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
          testId: 'pgdasd-row-actions',
          items: actionItems(row.original)
        })
      }
    }
  ]
}
