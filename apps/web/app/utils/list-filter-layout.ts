/**
 * Tokens de layout responsivo para filtros / busca / tabs do painel.
 * Mobile-first: scroll touch horizontal; desktop mantém faixa customers.vue.
 */

/** Scroll horizontal com gesto touch (tabs, chips, ações da toolbar). */
export const TOUCH_SCROLL_X
  = 'w-full max-w-full min-w-0 overflow-x-auto overscroll-x-contain [-webkit-overflow-scrolling:touch] touch-pan-x'

/** Faixa principal da ListFilterToolbar: empilha no xs, linha no sm+. */
export const LIST_FILTER_TOOLBAR_STACK
  = 'flex w-full min-w-0 flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between'

/** Campo de busca: full width no mobile; flexível com teto max-w-sm no sm+. */
export const LIST_FILTER_SEARCH_INPUT
  = 'w-full min-w-0 sm:w-40 sm:flex-1 sm:max-w-sm'

/**
 * Faixa de chips + ações: scroll horizontal no mobile (não quebra em 3 linhas);
 * no sm+ encolhe (flex-1) para o DataTableFilterRoot colapsar chips em contador
 * antes de empurrar Salvar/Colunas — nowrap + justify-end.
 */
export const LIST_FILTER_ACTIONS_ROW
  = `${TOUCH_SCROLL_X} flex w-full items-center gap-1.5 pb-0.5 sm:ml-auto sm:min-w-0 sm:flex-1 sm:flex-nowrap sm:justify-end sm:overflow-visible sm:pb-0`

/** Lista de chips DataTableFilter (nowrap + scroll no xs) — legado / host direto. */
export const DATA_TABLE_FILTERS_ROW
  = `${TOUCH_SCROLL_X} flex min-w-0 max-w-full items-center gap-1.5`

/**
 * Ajuste apenas estrutural para UTabs retraída. Cores, superfície, raio,
 * indicador e estados permanecem integralmente no tema padrão do Nuxt UI.
 */
export const SCROLLABLE_TABS_UI = {
  root: 'w-max',
  list: 'w-max flex-nowrap',
  trigger: 'min-w-max shrink-0 grow-0'
} as const

/**
 * O variant link usa a mesma restrição estrutural; sua aparência continua
 * sendo definida pelo variant nativo do Nuxt UI.
 */
export const LINK_TABS_UI = {
  root: 'w-max',
  list: 'w-max flex-nowrap',
  trigger: 'min-w-max shrink-0 grow-0'
} as const

/** Labels de botão: ícone no xs, texto a partir de sm (aria-label obrigatório). */
export const COMPACT_BUTTON_LABEL_UI = { label: 'hidden sm:inline' } as const
