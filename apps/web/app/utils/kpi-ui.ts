/**
 * Tokens visuais canônicos da faixa de KPIs do painel.
 *
 * Fonte: `.local/reference/nuxt-dashboard-template/app/components/home/HomeStats.vue`
 * + `apps/web/app/components/home/HomeStats.vue` / `MonitoringKpiStrip.vue`.
 *
 * Use via `ShellKpiStrip` ou, se precisar de markup custom, importe os helpers.
 */

export type KpiTone = 'default' | 'primary' | 'success' | 'warning' | 'error' | 'info'

export interface DashboardKpiItem {
  /** Chave estável (testid / v-for). */
  key: string
  /** Título curto em pt-BR (CSS uppercase no card). */
  title: string
  icon: string
  value: string | number
  /** Destino opcional (UPageCard `to`). */
  to?: string
  tone?: KpiTone
  /** Ícone de alerta ao lado do valor (ex.: crítico > 0). */
  critical?: boolean
  /** aria-label do card. */
  ariaLabel?: string
}

/** Classes do leading circular (ícone) por tom. */
export function kpiLeadingClass(tone: KpiTone = 'default', active = false): string {
  if (active) {
    return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col'
  }
  switch (tone) {
    case 'error':
      return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-error/10 ring ring-inset ring-error/25 flex-col'
    case 'warning':
      return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-warning/10 ring ring-inset ring-warning/25 flex-col'
    case 'success':
      return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-success/10 ring ring-inset ring-success/25 flex-col'
    case 'info':
      return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-info/10 ring ring-inset ring-info/25 flex-col'
    case 'primary':
    case 'default':
    default:
      return 'mb-1.5 p-2 sm:mb-2.5 sm:p-2.5 rounded-full bg-primary/10 ring ring-inset ring-primary/25 flex-col'
  }
}

/**
 * `:ui` do UPageCard no padrão HomeStats.
 * Textos sempre truncados — título/valor longos não esticam o card.
 */
export function kpiPageCardUi(tone: KpiTone = 'default', active = false) {
  return {
    root: 'min-w-0 max-w-full overflow-hidden h-full',
    container: 'min-w-0 max-w-full gap-y-1 overflow-hidden p-2.5 sm:gap-y-1.5 sm:p-4 lg:p-6',
    wrapper: 'min-w-0 max-w-full items-start overflow-hidden',
    header: 'min-w-0 max-w-full overflow-hidden',
    body: 'min-w-0 max-w-full overflow-hidden',
    leading: kpiLeadingClass(tone, active),
    // line-clamp-1 + block: uppercase long titles (ex.: "Módulos com erro") não quebram layout
    title: 'block w-full min-w-0 max-w-full truncate font-normal text-muted text-[10px] leading-tight uppercase sm:text-xs'
  } as const
}

/** Valor numérico do KPI — sempre 1 linha, não empurra o card. */
export function kpiValueClass(tone: KpiTone = 'default', hasAlert = false): string {
  const base = 'block min-w-0 max-w-full truncate text-lg font-semibold tabular-nums leading-tight sm:text-2xl'
  if (hasAlert && tone === 'error') return `${base} text-error`
  if (hasAlert && tone === 'warning') return `${base} text-warning`
  return `${base} text-highlighted`
}

/** Título exibido no card: normaliza espaços e evita strings vazias. */
export function kpiDisplayTitle(title?: string | null, maxLen = 28): string {
  const t = String(title || '').replace(/\s+/g, ' ').trim()
  if (!t) return '—'
  if (t.length <= maxLen) return t
  return `${t.slice(0, Math.max(1, maxLen - 1))}…`
}

/** Valor exibido: números como string estável; strings longas truncadas no display. */
export function kpiDisplayValue(value: string | number | null | undefined, maxLen = 12): string {
  if (value == null) return '—'
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return '—'
    return String(value)
  }
  const s = String(value).replace(/\s+/g, ' ').trim()
  if (!s) return '—'
  if (s.length <= maxLen) return s
  // Placeholder de loading
  if (s === '…' || s === '...') return s
  return `${s.slice(0, Math.max(1, maxLen - 1))}…`
}

/** Classes do grid colado (N colunas no lg). */
export function kpiGridClass(cols: number): string {
  const n = Math.min(Math.max(cols, 2), 6)
  switch (n) {
    case 2: return 'grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-2 lg:gap-px'
    case 3: return 'grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-3 lg:gap-px'
    case 5: return 'grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-5 lg:gap-px'
    case 6: return 'grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-6 lg:gap-px'
    default: return 'grid-cols-2 gap-2 sm:gap-4 lg:grid-cols-4 lg:gap-px'
  }
}

/**
 * Humaniza códigos de status em pt-BR quando não há mapa.
 * Preferir catálogos explícitos (`statusLabel`, `fiscalStatusMeta`, work-labels).
 */
export function humanizeStatusCode(value?: string | null): string {
  if (!value) return '—'
  const raw = String(value).trim()
  if (!raw) return '—'
  // Já legível (tem espaço ou acento comum)
  if (/\s/.test(raw) || /[áàâãéêíóôõúç]/i.test(raw)) return raw
  return raw
    .replace(/[_-]+/g, ' ')
    .toLowerCase()
    .replace(/\b\w/g, c => c.toUpperCase())
}
