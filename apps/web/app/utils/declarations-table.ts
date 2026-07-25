import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h } from 'vue'
import {
  FiscalClientCell,
  FiscalStatusBadge,
  MonitoringPgdasdDeclarationIndicator
} from '#components'
import UBadge from '@nuxt/ui/components/Badge.vue'
import UTooltip from '@nuxt/ui/components/Tooltip.vue'
import type { DeclarationsClientRow } from '~/types/fiscal-modules'
import { formatDateTime } from '~/utils/format'
import { pgdasdCanRequestAutomatic, pgdasdDeclarationMeta, formatPgdasdPeriod, pgdasdTrackingMeta } from '~/utils/pgdasd'
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
  MONITORING_SHARED_COLUMN_LABELS,
  type MonitoringSendColumnState
} from '~/utils/monitoring-table-columns'

function detailOf(row: DeclarationsClientRow) {
  return row.detail || {}
}

function clientHref(id: number) {
  return `/monitoring/clients/${id}/declarations`
}

/**
 * Colunas PGDAS do hub Declarações — spine com declaração:
 * Situação · Últ. Declaração · Cliente · Comunicação · Consulta · Ações.
 */
export function buildDeclarationsPgdasColumns(options: {
  onHistory: (row: DeclarationsClientRow) => void
  onOperations?: (row: DeclarationsClientRow) => void
  onEditClient?: (row: DeclarationsClientRow) => void
  onTracking?: (row: DeclarationsClientRow) => void
  onSend?: (row: DeclarationsClientRow) => void
  onToggleAutomatic?: (row: DeclarationsClientRow, value: boolean) => void
}): TableColumn<DeclarationsClientRow>[] {
  const DeclarationIndicator = MonitoringPgdasdDeclarationIndicator

  function actionItems(row: DeclarationsClientRow): DropdownMenuItem[][] {
    const items: DropdownMenuItem[] = [
      {
        label: 'Abrir cliente',
        icon: 'i-lucide-building-2',
        to: clientHref(row.client_id)
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
      label: 'Histórico de busca',
      icon: 'i-lucide-history',
      onSelect: () => options.onHistory(row)
    })
    if (options.onOperations) {
      items.push({
        label: 'Operações oficiais',
        icon: 'i-lucide-workflow',
        onSelect: () => options.onOperations?.(row)
      })
    }
    return [items]
  }

  function sendState(row: DeclarationsClientRow): MonitoringSendColumnState {
    const communication = detailOf(row).pgdasd?.communication || null
    const tracking = pgdasdTrackingMeta(communication?.tracking_status)
    return {
      trackingIcon: tracking.icon,
      trackingLabel: communication ? tracking.label : 'Sem histórico local',
      trackingColor: communication ? tracking.color : 'neutral',
      trackingDisabled: !communication || !options.onTracking,
      automaticRequested: communication?.automatic_requested === true,
      canToggleAutomatic: Boolean(options.onToggleAutomatic) && pgdasdCanRequestAutomatic(communication),
      canSend: communication?.can_send === true && Boolean(options.onSend)
    }
  }

  return [
    {
      id: 'situation',
      header: 'Situação da declaração',
      enableSorting: false,
      meta: { class: { th: 'w-36 min-w-28', td: 'w-36 min-w-28' } },
      cell: ({ row }) => {
        const d = detailOf(row.original)
        const state = d.declaration_state || d.pgdasd?.declaration_state
        if (state) {
          const meta = pgdasdDeclarationMeta(String(state))
          return h(UTooltip, {
            text: d.declaration_state_reason || d.pgdasd?.declaration_state_reason || meta.description
          }, {
            default: () => h('div', { class: 'block w-full min-w-0' }, [
              h(UBadge, tableCellBadgeProps({
                'label': meta.label,
                'color': meta.color,
                'icon': meta.icon,
                'aria-label': `Situação da declaração: ${meta.label}`,
                'data-testid': 'declarations-pgdas-situation'
              }))
            ])
          })
        }
        return h(FiscalStatusBadge, {
          fill: true,
          status: String(d.next_situation || row.original.situation || 'UNKNOWN')
        })
      }
    },
    {
      id: 'last_declaration',
      header: 'Últ. Declaração',
      enableSorting: false,
      meta: { class: { th: 'w-28 min-w-24', td: 'w-28 min-w-24' } },
      cell: ({ row }) => {
        const d = detailOf(row.original)
        const last = d.last_declaration || d.pgdasd?.latest_declaration
        const period = formatPgdasdPeriod(last?.period_key || d.next_period_key)
        const state = d.declaration_state || d.pgdasd?.declaration_state
        return h(DeclarationIndicator, {
          period: period === '—' ? null : period,
          state,
          reason: d.declaration_state_reason || d.pgdasd?.declaration_state_reason
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
        name: row.original.name || row.original.display_name,
        legalName: row.original.legal_name,
        cnpj: row.original.cnpj,
        cnpjMasked: row.original.cnpj_masked,
        to: clientHref(row.original.client_id)
      })
    },
    buildMonitoringComunicacaoColumn<DeclarationsClientRow>({
      getState: row => sendState(row),
      onTracking: row => options.onTracking?.(row),
      onSend: row => options.onSend?.(row),
      onToggleAutomatic: (row, value) => options.onToggleAutomatic?.(row, value),
      testIdPrefix: 'declarations-pgdas-tracking'
    }),
    {
      id: MONITORING_CONSULTED_ID,
      header: ({ column }) => sortHeader(MONITORING_CONSULTED_LABEL, column),
      meta: { ...MONITORING_CONSULTED_META },
      cell: ({ row }) => {
        const d = detailOf(row.original)
        const at = d.last_valid_query_at
          || d.pgdasd?.last_valid_query_at
          || row.original.last_consulted_at
          || row.original.last_snapshot_at
        return h('span', {
          'class': 'whitespace-nowrap tabular-nums text-xs text-muted',
          'data-testid': 'declarations-pgdas-last-search'
        }, formatDateTime(at || null))
      }
    },
    {
      id: 'actions',
      header: MONITORING_ACTIONS_LABEL,
      enableHiding: false,
      enableSorting: false,
      meta: { ...MONITORING_ACTIONS_META },
      cell: ({ row }) => buildMonitoringActionsMenuCell({
        ariaLabel: `Mais ações do cliente ${row.original.client_id}`,
        testId: 'declarations-pgdas-row-actions',
        items: actionItems(row.original)
      })
    }
  ]
}

/** Colunas genéricas filtradas por obrigação (DCTFWeb / DEFIS). */
export function buildDeclarationsObligationColumns(options: {
  onHistory?: (row: DeclarationsClientRow) => void
  onOperations?: (row: DeclarationsClientRow) => void
  onEditClient?: (row: DeclarationsClientRow) => void
  historyLabel?: string
}): TableColumn<DeclarationsClientRow>[] {
  function actionItems(row: DeclarationsClientRow): DropdownMenuItem[][] {
    const items: DropdownMenuItem[] = [
      {
        label: 'Abrir cliente',
        icon: 'i-lucide-building-2',
        to: clientHref(row.client_id)
      }
    ]
    if (options.onEditClient) {
      items.push({
        label: 'Editar cliente',
        icon: 'i-lucide-pencil',
        onSelect: () => options.onEditClient?.(row)
      })
    }
    if (options.onHistory) {
      items.push({
        label: options.historyLabel || 'Histórico',
        icon: 'i-lucide-history',
        onSelect: () => options.onHistory?.(row)
      })
    }
    if (options.onOperations) {
      items.push({
        label: 'Operações oficiais',
        icon: 'i-lucide-workflow',
        onSelect: () => options.onOperations?.(row)
      })
    }
    return [items]
  }

  return [
    {
      id: 'situation',
      header: ({ column }) => sortHeader('Situação', column),
      cell: ({ row }) => h(FiscalStatusBadge, {
        fill: true,
        status: String(
          detailOf(row.original).next_situation || row.original.situation
        )
      })
    },
    {
      id: 'client',
      header: ({ column }) => sortHeader('Cliente', column),
      enableHiding: false,
      meta: { ...MONITORING_CLIENT_COLUMN_META },
      cell: ({ row }) => h(FiscalClientCell, {
        clientId: row.original.client_id,
        name: row.original.name || row.original.display_name,
        legalName: row.original.legal_name,
        cnpj: row.original.cnpj,
        cnpjMasked: row.original.cnpj_masked,
        to: clientHref(row.original.client_id)
      })
    },
    {
      id: 'obligation',
      header: 'Obrigação',
      enableSorting: false,
      cell: ({ row }) => String(detailOf(row.original).next_obligation_code || '—')
    },
    {
      id: 'competence',
      header: ({ column }) => sortHeader('Competência', column),
      cell: ({ row }) => String(
        row.original.competence
        || detailOf(row.original).next_period_key
        || '—'
      )
    },
    {
      id: MONITORING_CONSULTED_ID,
      header: MONITORING_SHARED_COLUMN_LABELS.consulted || 'Consulta',
      enableSorting: false,
      meta: { ...MONITORING_CONSULTED_META },
      cell: ({ row }) => formatDateTime(
        row.original.last_consulted_at || row.original.last_snapshot_at || null
      )
    },
    {
      id: 'actions',
      header: MONITORING_ACTIONS_LABEL,
      enableHiding: false,
      enableSorting: false,
      meta: { ...MONITORING_ACTIONS_META },
      cell: ({ row }) => buildMonitoringActionsMenuCell({
        ariaLabel: `Mais ações do cliente ${row.original.client_id}`,
        testId: 'declarations-obligation-row-actions',
        items: actionItems(row.original)
      })
    }
  ]
}

/** Colunas FGTS parciais (sem inventar guia/pagamento). */
export function buildDeclarationsFgtsColumns(options?: {
  onEditClient?: (row: DeclarationsClientRow) => void
}): TableColumn<DeclarationsClientRow>[] {
  function actionItems(row: DeclarationsClientRow): DropdownMenuItem[][] {
    const items: DropdownMenuItem[] = [
      {
        label: 'Abrir cliente',
        icon: 'i-lucide-building-2',
        to: `/monitoring/clients/${row.client_id}/fgts`
      }
    ]
    if (options?.onEditClient) {
      items.push({
        label: 'Editar cliente',
        icon: 'i-lucide-pencil',
        onSelect: () => options.onEditClient?.(row)
      })
    }
    return [items]
  }

  return [
    {
      id: 'situation',
      header: ({ column }) => sortHeader('Situação', column),
      cell: ({ row }) => h(FiscalStatusBadge, {
        fill: true,
        status: String(row.original.situation || 'UNKNOWN')
      })
    },
    {
      id: 'client',
      header: ({ column }) => sortHeader('Cliente', column),
      enableHiding: false,
      meta: { ...MONITORING_CLIENT_COLUMN_META },
      cell: ({ row }) => h(FiscalClientCell, {
        clientId: row.original.client_id,
        name: row.original.name || row.original.display_name,
        legalName: row.original.legal_name,
        cnpj: row.original.cnpj,
        cnpjMasked: row.original.cnpj_masked,
        to: `/monitoring/clients/${row.original.client_id}/fgts`
      })
    },
    {
      id: 'competence',
      header: 'Competência',
      enableSorting: false,
      cell: ({ row }) => String(
        detailOf(row.original).next_period_key
        || detailOf(row.original).fgts?.competence_period_key
        || row.original.competence
        || '—'
      )
    },
    {
      id: 'closure',
      header: 'Fechamento',
      enableSorting: false,
      cell: ({ row }) => String(detailOf(row.original).fgts?.closure_status || '—')
    },
    {
      id: 'totalization',
      header: 'Totalização',
      enableSorting: false,
      cell: ({ row }) => String(detailOf(row.original).fgts?.totalization_status || '—')
    },
    {
      id: 'coverage',
      header: 'Cobertura',
      enableSorting: false,
      cell: ({ row }) => h(FiscalStatusBadge, {
        fill: true,
        status: String(
          detailOf(row.original).fgts?.coverage
          || row.original.coverage
          || 'PARTIAL'
        )
      })
    },
    {
      id: 'actions',
      header: MONITORING_ACTIONS_LABEL,
      enableHiding: false,
      enableSorting: false,
      meta: { ...MONITORING_ACTIONS_META },
      cell: ({ row }) => buildMonitoringActionsMenuCell({
        ariaLabel: `Mais ações do cliente ${row.original.client_id}`,
        testId: 'declarations-fgts-row-actions',
        items: actionItems(row.original)
      })
    }
  ]
}

export const DECLARATIONS_PGDAS_COLUMN_LABELS = {
  situation: 'Situação da declaração',
  last_declaration: 'Últ. Declaração',
  client: 'Cliente',
  ...MONITORING_SHARED_COLUMN_LABELS
} as const
