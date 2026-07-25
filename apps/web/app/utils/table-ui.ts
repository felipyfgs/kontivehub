/**
 * Presets de UTable — cópia do template @ 0f30c09 + densidade do painel.
 *
 * Regra de ouro (kit Shell*):
 * - Lista admin = `ShellDataTable` (+ `ShellTableFooter` / toolbar shell).
 * - Page/domínio NÃO montam `<UTable` nem paginação/per-page na mão.
 * - Prefixo auto-import: pasta `components/shell/` → `Shell*`.
 *
 * Tema Nuxt UI (`.nuxt/ui/table.ts`) default:
 *   th → `px-4 py-3.5` · td → `p-4`
 * Os presets abaixo sobrescrevem padding via `ui` (tailwind-merge) sem reduzir
 * `text-sm` do tema — só apertam ar vertical/horizontal.
 *
 * - DASHBOARD_TABLE_UI ← customers.vue (lista admin)
 * - COMPACT_DASHBOARD_TABLE_UI ← HomeSales.vue + py/px densos
 * - MONITORING_COMPACT_TABLE_UI ← carteiras fiscais (ModuleDataTable)
 *
 * Layout de lista (customers.vue):
 * - LIST_TABLE_CLASS → UTable `shrink-0` (altura natural)
 * - LIST_TABLE_FOOTER_CLASS → contagem + UPagination com `mt-auto`
 * - LIST_TABLE_STACK_CLASS → coluna flex que preenche o #body do painel
 *
 * Contrato de célula:
 * - UBadge de status/chip: size="md" + TABLE_CELL_BADGE_* (preenche a célula)
 * - UButton ícone: size="xs", variant="ghost"
 * - Larguras só em column.meta.class
 *
 * Larguras em `table-fixed` (evita «coluna fantasma» à direita):
 * - Coluna de identidade (Cliente): `w-full max-w-0` (`MONITORING_CLIENT_COLUMN_META`)
 *   — absorve o espaço restante e permite ellipsis abaixo do min-content do texto.
 *   Não usar `min-w-48` nas carteiras (força scroll horizontal).
 * - Demais colunas: largura fixa em rem (`w-28 min-w-24`), sem `%` baixo.
 *   Evitar `max-w-*` arbitrário (ex.: max-w-xs) na identidade — deixa faixa vazia;
 *   `max-w-0` + `w-full` é a exceção intencional do truque de ellipsis.
 *
 * Preferir caber na viewport (sem barra horizontal):
 * - NÃO forçar `min-w-[960px]` / `min-w-4xl` na `<table>` só para “alinhar ícones”.
 * - Usar `w-full min-w-0` na grade; `horizontalScroll` só como escape hatch.
 * - Colunas secundárias começam ocultas (`initialHiddenColumns`) e voltam via «Colunas».
 */

/** UTable em lista admin — customers.vue `class="shrink-0"`. */
export const LIST_TABLE_CLASS = 'shrink-0'

/**
 * Footer de lista — customers.vue + stack em &lt; sm:
 * empilha contagem e controles no telefone; linha em sm+.
 */
export const LIST_TABLE_FOOTER_CLASS
  = 'flex flex-col gap-3 border-t border-default pt-4 mt-auto sm:flex-row sm:items-center sm:justify-between'

/**
 * Stack toolbar + tabela + footer dentro do #body (flex do painel).
 * `min-h-full flex-1` faz o `mt-auto` do footer empurrar ao fim da página.
 */
export const LIST_TABLE_STACK_CLASS = 'flex min-h-full min-w-0 flex-1 flex-col gap-1.5'

/** Opções do USelect «N por página» (lista de clientes / ModuleDataTable). */
export const LIST_TABLE_PER_PAGE_ITEMS = Object.freeze([
  { label: '10 por página', value: 10 },
  { label: '20 por página', value: 20 },
  { label: '50 por página', value: 50 }
] as const)

export type ListTablePerPage = (typeof LIST_TABLE_PER_PAGE_ITEMS)[number]['value']

/** Valores permitidos no seletor / contrato de lista offset. */
export const LIST_TABLE_PER_PAGE_VALUES: readonly ListTablePerPage[] = LIST_TABLE_PER_PAGE_ITEMS.map(
  item => item.value
)

/**
 * Normaliza per-page para 10 | 20 | 50 (default 20).
 * Evita UPagination com pageCount fantasma quando a API/URL manda outro valor.
 */
export function normalizeListTablePerPage(
  value: unknown,
  fallback: ListTablePerPage = 20
): ListTablePerPage {
  const n = Number(value)
  if (n === 10 || n === 20 || n === 50) return n
  return fallback
}

/** Quantidade de páginas offset (mínimo 1). */
export function listTablePageCount(total: number, itemsPerPage: number): number {
  const size = Math.max(1, Number(itemsPerPage) || 1)
  const n = Math.max(0, Number(total) || 0)
  return Math.max(1, Math.ceil(n / size))
}

/** Página atual limitada a `[1, pageCount]`. */
export function clampListTablePage(page: number, pageCount: number): number {
  const p = Math.floor(Number(page) || 1)
  const max = Math.max(1, Math.floor(Number(pageCount) || 1))
  return Math.min(Math.max(1, p), max)
}

/** Classes do badge que preenche a célula (padrão clients/index). */
export const TABLE_CELL_BADGE_CLASS = 'h-8 w-full min-w-0 justify-center tabular-nums font-normal'

/** `:ui` do UBadge em célula — ocupa a largura útil sem mexer no padding do td. */
export const TABLE_CELL_BADGE_UI = Object.freeze({
  base: 'h-8 w-full min-w-0 justify-center rounded-md',
  label: 'truncate text-center'
})

/**
 * Props canônicas para UBadge em célula de UTable (`h()` / template).
 * Mantém variant subtle por padrão; overrides mesclam `class`/`ui`.
 */
export function tableCellBadgeProps(overrides: Record<string, unknown> = {}) {
  const extraClass = typeof overrides.class === 'string' ? overrides.class : undefined
  const extraUi = overrides.ui && typeof overrides.ui === 'object'
    ? overrides.ui as Record<string, string>
    : undefined
  const { class: _c, ui: _u, ...rest } = overrides
  return {
    size: 'md' as const,
    variant: 'subtle' as const,
    class: [TABLE_CELL_BADGE_CLASS, extraClass].filter(Boolean).join(' '),
    ui: { ...TABLE_CELL_BADGE_UI, ...extraUi },
    ...rest
  }
}

/**
 * Anatomia customers.vue + padding denso (anula py-3.5 / p-4 do tema).
 * Fonte permanece text-sm do UTable.
 */
const customersTableUi = Object.freeze({
  base: 'table-fixed border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'px-3 py-1.5 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'px-3 py-1 border-b border-default',
  separator: 'h-0'
})

export const DASHBOARD_TABLE_UI = customersTableUi

/** Alias histórico — mesma anatomia de customers.vue (não reexporta o mesmo nome). */
export const DENSE_DASHBOARD_TABLE_UI = customersTableUi

/**
 * HomeSales.vue — header/linhas mais apertados que a lista admin.
 * Anula explicitamente px-4/py-3.5/p-4 do tema Nuxt UI.
 */
export const COMPACT_DASHBOARD_TABLE_UI = Object.freeze({
  base: 'table-fixed border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'px-2 py-1 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'px-2 py-0.5 border-b border-default',
  separator: 'h-0'
})

/**
 * Carteiras de monitoramento — Compact + padding horizontal responsivo.
 * Usado por ModuleDataTable (todas as grades fiscais desktop).
 */
export const MONITORING_COMPACT_TABLE_UI = Object.freeze({
  root: 'overflow-visible',
  base: COMPACT_DASHBOARD_TABLE_UI.base,
  thead: `bg-default ${COMPACT_DASHBOARD_TABLE_UI.thead}`,
  tbody: COMPACT_DASHBOARD_TABLE_UI.tbody,
  th: 'px-2 sm:px-2.5 py-1 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'px-2 sm:px-2.5 py-0.5 border-b border-default',
  separator: 'h-0'
})
