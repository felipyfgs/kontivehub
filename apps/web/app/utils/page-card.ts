/**
 * Mapa canônico de cards do painel (UPageCard / ShellKpiStrip).
 *
 * | Papel | Onde | Variante | Notas |
 * |-------|------|----------|--------|
 * | **kpi** | Home, monitoring, clients stats | ShellKpiStrip | grid 2 colunas mobile / colado no lg |
 * | **section-header** | Settings, forms | naked + horizontal | título breve + ação Save |
 * | **section-body** | Settings, detalhe | subtle | formulário / lista |
 * | **panel** | Dashboard blocos | subtle | conteúdo secundário |
 * | **auth** | login / 2FA | subtle | max-w no layout auth |
 * | **list-row** | dentro de panel | — | não usar UPageCard por linha |
 *
 * Acordeão (`ShellPanelAccordion` / `UAccordion`): painéis secundários empilhados
 * (não KPIs, não toolbar). Slots customizados: use `#{{slot}}-body` para herdar
 * o wrapper com padding do tema; `#{{slot}}` substitui `#content` sem inset.
 * Texto: breve (ui-copy / kpiDisplay*).
 */

export type PageCardRole
  = | 'kpi'
    | 'section-header'
    | 'section-body'
    | 'panel'
    | 'auth'

/** Classes de root do UPageCard por papel. */
export function pageCardRootClass(role: PageCardRole): string {
  switch (role) {
    case 'section-header':
      return 'mb-4 min-w-0'
    case 'section-body':
    case 'panel':
      return 'min-w-0 overflow-hidden'
    case 'auth':
      return 'w-full min-w-0'
    case 'kpi':
    default:
      return 'min-w-0 overflow-hidden'
  }
}

export const PAGE_CARD_SECTION_HEADER = {
  variant: 'naked' as const,
  orientation: 'horizontal' as const,
  class: 'mb-4 min-w-0'
}

export const PAGE_CARD_SECTION_BODY = {
  variant: 'subtle' as const,
  class: 'min-w-0 overflow-hidden'
}

export const PAGE_CARD_PANEL = {
  variant: 'subtle' as const,
  class: 'min-w-0 overflow-hidden'
}
