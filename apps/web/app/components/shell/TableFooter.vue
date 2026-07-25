<script setup lang="ts">
/**
 * Footer de lista admin — customers.vue @ 0f30c09 + seletor de clientes:
 * contagem · USelect «N por página» · UPagination · `mt-auto`.
 * Em viewport &lt; sm empilha/compacta controles sem overflow.
 *
 * Paginação: per-page só 10/20/50; UPagination permanece sempre (mesmo com
 * uma página ou 0 registros) para consistência com o arquétipo de lista.
 */
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import {
  LIST_TABLE_FOOTER_CLASS,
  LIST_TABLE_PER_PAGE_ITEMS,
  clampListTablePage,
  listTablePageCount,
  normalizeListTablePerPage,
  type ListTablePerPage
} from '~/utils/table-ui'

const props = withDefaults(defineProps<{
  /** Linhas selecionadas (0 se sem seleção). */
  selectedCount?: number
  /** Total de linhas no escopo (filtrado / API). */
  total: number
  page: number
  itemsPerPage?: number
  /** Exibe USelect de linhas por página. */
  showPerPage?: boolean
  /** Label a11y do seletor. */
  perPageAriaLabel?: string
  /** Exibe UPagination (default: sempre, inclusive com uma página). */
  showPagination?: boolean
  siblingCount?: number
  showEdges?: boolean
  testId?: string
}>(), {
  selectedCount: 0,
  itemsPerPage: 20,
  showPerPage: true,
  perPageAriaLabel: 'Linhas por página',
  showPagination: true,
  siblingCount: 1,
  showEdges: true,
  testId: 'list-table-footer'
})

const emit = defineEmits<{
  'update:page': [page: number]
  'update:itemsPerPage': [perPage: number]
}>()

const breakpoints = useBreakpoints(breakpointsTailwind)
const isNarrow = breakpoints.smaller('sm')

const resolvedItemsPerPage = computed(() =>
  normalizeListTablePerPage(props.itemsPerPage, 20)
)

const pageCount = computed(() =>
  listTablePageCount(props.total, resolvedItemsPerPage.value)
)

const resolvedPage = computed(() =>
  clampListTablePage(props.page, pageCount.value)
)

const perPageModel = computed<ListTablePerPage>({
  get: () => resolvedItemsPerPage.value,
  set: (value: ListTablePerPage) => emit('update:itemsPerPage', value)
})

const resolvedSiblingCount = computed(() =>
  isNarrow.value ? 0 : props.siblingCount
)
const resolvedShowEdges = computed(() =>
  isNarrow.value ? false : props.showEdges
)

/** Corrige per-page fora do contrato (ex.: API/URL com 15/25/100). */
watch(
  () => props.itemsPerPage,
  (value) => {
    const normalized = normalizeListTablePerPage(value, 20)
    if (Number(value) !== normalized) {
      emit('update:itemsPerPage', normalized)
    }
  },
  { immediate: true }
)

/** Mantém página dentro de `[1, pageCount]` quando o total encolhe. */
watch(
  [() => props.page, pageCount],
  ([page, count]) => {
    const clamped = clampListTablePage(page, count)
    if (clamped !== Number(page)) {
      emit('update:page', clamped)
    }
  },
  { immediate: true }
)
</script>

<template>
  <div
    :class="LIST_TABLE_FOOTER_CLASS"
    :data-testid="testId"
  >
    <div class="min-w-0 text-sm text-muted">
      <slot>
        <template v-if="selectedCount">
          <span class="tabular-nums">{{ selectedCount }}</span> selecionado(s)
          <span class="text-dimmed"> · </span>
        </template>
        <span class="tabular-nums">{{ total }}</span> registro(s)
      </slot>
    </div>

    <div
      class="flex w-full min-w-0 shrink-0 flex-wrap items-center justify-between gap-1.5 sm:w-auto sm:justify-end"
      data-testid="list-table-footer-controls"
    >
      <USelect
        v-if="showPerPage"
        v-model="perPageModel"
        :items="[...LIST_TABLE_PER_PAGE_ITEMS]"
        value-key="value"
        class="w-28 sm:w-36"
        :aria-label="perPageAriaLabel"
        data-testid="list-table-per-page"
      />
      <slot name="trailing" />
      <UPagination
        v-if="showPagination"
        :page="resolvedPage"
        :items-per-page="resolvedItemsPerPage"
        :total="total"
        :sibling-count="resolvedSiblingCount"
        :show-edges="resolvedShowEdges"
        @update:page="emit('update:page', $event)"
      />
    </div>
  </div>
</template>
