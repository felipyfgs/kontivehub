/**
 * Contrato transversal das colunas de carteira monitoring.
 *
 * Com declaração (PGDAS-D, DCTFWeb, hub Declarações PGDAS):
 *   Situação · Últ. Declaração|Declaração · [valores] · Cliente · Comunicação · Consulta · Ações
 *   PGDAS-D: Situação = pagamento DAS; Declaração = entrega (MM/YYYY colorido); sem coluna Pagamento.
 *
 * Sem Últ. Declaração (PGMEI, SITFIS, FGTS, MIT):
 *   Situação · Cliente · [domínio] · Comunicação · Consulta · Ações
 *
 * Histórico de busca NÃO entra na grade — só no menu Ações.
 * Comunicação = Send + Switch (automatic_requested) + ícone de rastreio.
 * Ações = última coluna; botão rotulado (não só ícone).
 */
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import { h } from 'vue'
import UButton from '@nuxt/ui/components/Button.vue'
import UDropdownMenu from '@nuxt/ui/components/DropdownMenu.vue'
import USwitch from '@nuxt/ui/components/Switch.vue'
import UTooltip from '@nuxt/ui/components/Tooltip.vue'
import { formatDate, formatDateTime } from '~/utils/format'
import { sortHeader } from '~/utils/table-sort'
import { tableIconButton } from '~/utils/table-icon-slots'

export const MONITORING_CONSULTED_ID = 'consulted'
/** Cabeçalho curto (tabela); tooltip/célula mantêm o sentido completo. */
export const MONITORING_CONSULTED_LABEL = 'Consulta'
export const MONITORING_COMUNICACAO_ID = 'comunicacao'
export const MONITORING_COMUNICACAO_LABEL = 'Comunicação'
export const MONITORING_ACTIONS_ID = 'actions'
export const MONITORING_ACTIONS_LABEL = 'Ações'
/** Mantido para labels do menu ⋮ — não usar como coluna da grade. */
export const MONITORING_HISTORY_ID = 'history'
export const MONITORING_HISTORY_LABEL = 'Histórico'

/**
 * Auto-ajuste em `table-fixed`: `w-0` + nowrap encolhe ao rótulo/data
 * (evita a coluna «Consulta» absorver faixa vazia).
 */
export const MONITORING_CONSULTED_META = {
  class: { th: 'w-0 whitespace-nowrap', td: 'w-0 whitespace-nowrap' }
} as const

/** Coluna Comunicação: Send · Switch · rastreio. */
export const MONITORING_COMUNICACAO_META = {
  class: { th: 'w-36 min-w-32', td: 'w-36 min-w-32' }
} as const

/** Ações: botão rotulado no fim da spine. */
export const MONITORING_ACTIONS_META = {
  class: { th: 'w-0 whitespace-nowrap', td: 'w-0 whitespace-nowrap' }
} as const

/**
 * Coluna Cliente — identidade flexível em `table-fixed`.
 * `max-w-0` + `w-full` é o truque clássico: a coluna absorve o espaço
 * restante e ainda pode encolher abaixo do min-content do texto, para o
 * ellipsis do `FiscalClientCell` funcionar (sem `min-w-48` nem scroll).
 */
export const MONITORING_CLIENT_COLUMN_META = {
  class: { th: 'w-full max-w-0', td: 'w-full max-w-0 overflow-hidden' }
} as const

/** Labels padrão para `column-labels` / mobile cards (sem history na grade). */
export const MONITORING_SHARED_COLUMN_LABELS: Record<string, string> = {
  [MONITORING_ACTIONS_ID]: MONITORING_ACTIONS_LABEL,
  [MONITORING_COMUNICACAO_ID]: MONITORING_COMUNICACAO_LABEL,
  [MONITORING_CONSULTED_ID]: MONITORING_CONSULTED_LABEL
}

export interface MonitoringEnvioColumnState {
  automaticRequested: boolean
  canToggleAutomatic?: boolean
  canSend?: boolean
  sendBusy?: boolean
  toggleBusy?: boolean
}

export interface MonitoringTrackingColumnState {
  trackingIcon: string
  trackingLabel: string
  trackingColor?: 'neutral' | 'primary' | 'success' | 'warning' | 'error' | 'info'
  trackingDisabled?: boolean
}

/** Estado combinado da coluna Comunicação. */
export type MonitoringSendColumnState = MonitoringEnvioColumnState & MonitoringTrackingColumnState

/**
 * Coluna Comunicação — Send · Switch · ícone de rastreio.
 */
export function buildMonitoringComunicacaoColumn<T>(options: {
  getState: (row: T) => MonitoringSendColumnState
  onTracking: (row: T) => void
  onSend: (row: T) => void
  onToggleAutomatic: (row: T, value: boolean) => void
  testIdPrefix?: string
}): TableColumn<T> {
  const prefix = options.testIdPrefix ?? 'monitoring-comunicacao'
  return {
    id: MONITORING_COMUNICACAO_ID,
    header: MONITORING_COMUNICACAO_LABEL,
    enableSorting: false,
    meta: { ...MONITORING_COMUNICACAO_META },
    cell: ({ row }) => {
      const state = options.getState(row.original)
      const sendBtn = h(UTooltip, { text: state.canSend === false ? 'Envio indisponível' : 'Enviar documentos' }, {
        default: () => h(UButton, {
          'size': 'xs',
          'color': 'primary',
          'variant': 'ghost',
          'icon': 'i-lucide-send',
          'ariaLabel': 'Enviar',
          'disabled': state.canSend === false || state.sendBusy === true,
          'loading': state.sendBusy === true,
          'data-testid': `${prefix}-send`,
          'onClick': () => options.onSend(row.original)
        })
      })
      const autoSwitch = h(UTooltip, { text: 'Envio automático na consulta agendada' }, {
        default: () => h(USwitch, {
          'size': 'sm',
          'checked': state.automaticRequested,
          'disabled': state.canToggleAutomatic === false || state.toggleBusy === true,
          'ariaLabel': 'Envio automático na consulta agendada',
          'data-testid': `${prefix}-auto`,
          'onUpdate:checked': (value: boolean) => options.onToggleAutomatic(row.original, value)
        })
      })
      const trackingBtn = tableIconButton({
        label: state.trackingLabel,
        icon: state.trackingIcon,
        color: state.trackingColor || 'neutral',
        testId: `${prefix}-status`,
        disabled: state.trackingDisabled === true,
        onClick: () => options.onTracking(row.original)
      })
      return h('div', {
        'class': 'inline-flex items-center gap-1',
        'data-testid': `${prefix}-cell`
      }, [sendBtn, autoSwitch, trackingBtn])
    }
  }
}

/**
 * Célula Ações — mesmo padrão Nuxt UI das Ações em massa:
 * UDropdownMenu + UButton (label + icon, variant subtle).
 */
export function buildMonitoringActionsMenuCell(options: {
  ariaLabel: string
  testId: string
  items: DropdownMenuItem[][]
}) {
  return h(UDropdownMenu, {
    items: options.items,
    content: { align: 'end' }
  }, () => h(UButton, {
    'label': MONITORING_ACTIONS_LABEL,
    'icon': 'i-lucide-ellipsis-vertical',
    'color': 'neutral',
    'variant': 'subtle',
    'size': 'xs',
    'ui': { label: 'hidden sm:inline' },
    'aria-label': options.ariaLabel,
    'data-testid': options.testId
  }))
}

/**
 * Coluna canônica «Consulta» — timestamp da última busca/sync válida.
 */
export function buildMonitoringConsultedColumn<T>(options: {
  getAt: (row: T) => string | null | undefined
  /** date = só data; datetime = data+hora. */
  format?: 'date' | 'datetime'
  sortable?: boolean
  testId?: string
}): TableColumn<T> {
  const format = options.format ?? 'date'
  const testId = options.testId ?? 'monitoring-last-consulted'
  return {
    id: MONITORING_CONSULTED_ID,
    header: options.sortable === false
      ? MONITORING_CONSULTED_LABEL
      : ({ column }) => sortHeader(MONITORING_CONSULTED_LABEL, column),
    enableSorting: options.sortable !== false,
    meta: { ...MONITORING_CONSULTED_META },
    cell: ({ row }) => {
      const raw = options.getAt(row.original)
      const label = format === 'datetime' ? formatDateTime(raw) : formatDate(raw)
      const full = raw ? formatDateTime(raw) : 'Nenhuma consulta registrada'
      return h(UTooltip, { text: full }, {
        default: () => h('span', {
          'class': 'whitespace-nowrap tabular-nums text-xs text-muted',
          'data-testid': testId
        }, label)
      })
    }
  }
}
