<script setup lang="ts" generic="T">
/**
 * Cards mobile de lista admin — composição shell compartilhada.
 *
 * Viewport &lt; md (via pai): um card por linha com cabeçalho (identidade + status),
 * resumo, detalhes colapsáveis e ações. Reusa `column.cell` ou slots `#*-cell`
 * encaminhados pelo ShellDataTable.
 *
 * @see components/shell/DataTable.vue
 * @see components/monitoring/ModuleMobileCards.vue
 */
import type { TableColumn } from '@nuxt/ui'
import type { PropType, VNode } from 'vue'
import { defineComponent, h, watch, computed, ref } from 'vue'
import { upperFirst } from 'scule'
import { UButton, UCard, UCheckbox, UCollapsible, USkeleton } from '#components'
import { formatCnpj } from '~/utils/format'
import ShellListEmpty from '~/components/shell/ListEmpty.vue'

/** Colunas de ação — sempre no rodapé do card. */
const ACTION_IDS = new Set(['actions', 'send', 'history', 'select'])

const props = withDefaults(defineProps<{
  rows: T[]
  columns: TableColumn<T>[]
  getRowId: (row: T, index: number) => string
  selectionEnabled?: boolean
  rowSelection?: Record<string, boolean>
  columnLabels?: Record<string, string>
  loading?: boolean
  emptyTitle?: string
  emptyDescription?: string
  emptyKind?: 'empty' | 'filtered' | 'error'
  error?: string | null
  /** Coluna de identidade no cabeçalho. */
  primaryColumnId?: string
  /** Coluna de status no canto do cabeçalho. */
  statusColumnId?: string
  /** Campos no resumo aberto; omitir = demais colunas de conteúdo. */
  summaryColumnIds?: string[]
  testId?: string
}>(), {
  selectionEnabled: false,
  rowSelection: () => ({}),
  columnLabels: () => ({}),
  loading: false,
  emptyTitle: undefined,
  emptyDescription: undefined,
  emptyKind: 'empty',
  error: null,
  primaryColumnId: undefined,
  statusColumnId: undefined,
  summaryColumnIds: undefined,
  testId: 'shell-mobile-cards'
})

const emit = defineEmits<{
  'update:rowSelection': [value: Record<string, boolean>]
  'retry': []
}>()

const slots = useSlots()

function columnId(column: TableColumn<T>): string {
  return String(column.id || (column as { accessorKey?: string }).accessorKey || '')
}

function columnLabel(column: TableColumn<T>): string {
  const id = columnId(column)
  if (props.columnLabels[id]) return props.columnLabels[id]
  if (typeof column.header === 'string' && column.header.trim()) return column.header
  return upperFirst(id || 'campo')
}

const contentColumns = computed(() =>
  props.columns.filter((column) => {
    const id = columnId(column)
    return id && id !== 'select'
  })
)

const resolvedPrimaryId = computed(() => {
  if (props.primaryColumnId) return props.primaryColumnId
  const first = contentColumns.value.find(column => !ACTION_IDS.has(columnId(column)))
  return first ? columnId(first) : ''
})

const resolvedStatusId = computed(() => {
  if (props.statusColumnId) return props.statusColumnId
  const preferred = ['status', 'situation', 'is_active', 'result']
  for (const id of preferred) {
    if (contentColumns.value.some(column => columnId(column) === id)) return id
  }
  return ''
})

const headerIds = computed(() => new Set(
  [resolvedPrimaryId.value, resolvedStatusId.value].filter(Boolean)
))

const summaryIds = computed(() => {
  if (props.summaryColumnIds?.length) return new Set(props.summaryColumnIds)
  return new Set(
    contentColumns.value
      .map(columnId)
      .filter(id => id && !headerIds.value.has(id) && !ACTION_IDS.has(id))
  )
})

const summaryColumns = computed(() =>
  contentColumns.value.filter((column) => {
    const id = columnId(column)
    return summaryIds.value.has(id) && !headerIds.value.has(id) && !ACTION_IDS.has(id)
  })
)

const detailColumns = computed(() =>
  contentColumns.value.filter((column) => {
    const id = columnId(column)
    return !headerIds.value.has(id) && !ACTION_IDS.has(id) && !summaryIds.value.has(id)
  })
)

const actionColumns = computed(() =>
  contentColumns.value.filter(column => ACTION_IDS.has(columnId(column)) && columnId(column) !== 'select')
)

const primaryColumn = computed(() =>
  contentColumns.value.find(column => columnId(column) === resolvedPrimaryId.value) || null
)

const statusColumn = computed(() =>
  resolvedStatusId.value
    ? contentColumns.value.find(column => columnId(column) === resolvedStatusId.value) || null
    : null
)

function isSelected(rowId: string) {
  return props.rowSelection[rowId] === true
}

function toggleSelected(rowId: string, value: boolean | 'indeterminate') {
  const next = value === true
    ? { ...props.rowSelection, [rowId]: true }
    : Object.fromEntries(
      Object.entries(props.rowSelection).filter(([key]) => key !== rowId)
    ) as typeof props.rowSelection

  emit('update:rowSelection', next)
}

function mockRow(original: T, index: number) {
  const id = props.getRowId(original, index)
  return {
    id,
    index,
    original,
    getIsSelected: () => isSelected(id),
    toggleSelected: (value: boolean) => toggleSelected(id, value),
    getValue: (key: string) => (original as Record<string, unknown>)[key]
  }
}

function hasCellSlot(id: string) {
  return Boolean(slots[`${id}-cell`])
}

function renderCell(column: TableColumn<T>, original: T, index: number): VNode | string | number | null {
  const cell = column.cell
  if (typeof cell === 'function') {
    try {
      const row = mockRow(original, index)
      return cell({
        row,
        getValue: row.getValue,
        renderValue: () => row.getValue(columnId(column)),
        column,
        table: {} as never,
        cell: {} as never
      } as never) as VNode | string | number | null
    } catch {
      return '—'
    }
  }
  const key = (column as { accessorKey?: string }).accessorKey
  if (key) {
    const value = (original as Record<string, unknown>)[key]
    if (value == null || value === '') return '—'
    return String(value)
  }
  return null
}

const CellHost = defineComponent({
  name: 'ShellMobileCellHost',
  props: {
    column: { type: Object as PropType<unknown>, required: true },
    original: { type: Object as PropType<unknown>, required: true },
    index: { type: Number, required: true }
  },
  setup(hostProps) {
    return () => {
      const node = renderCell(
        hostProps.column as TableColumn<T>,
        hostProps.original as T,
        hostProps.index
      )
      if (node == null) return h('span', { class: 'text-muted' }, '—')
      if (typeof node === 'string' || typeof node === 'number') {
        return h('span', { class: 'text-sm text-highlighted' }, String(node))
      }
      return node
    }
  }
})

function identityFallback(original: T): string {
  const row = original as {
    legal_name?: string
    name?: string
    display_name?: string
    title?: string
    client_id?: number
    id?: number | string
  }
  return row.legal_name
    || row.display_name
    || row.name
    || row.title
    || (row.client_id ? `Cliente #${row.client_id}` : '')
    || (row.id != null ? `#${row.id}` : 'Registro')
}

function cnpjFallback(original: T): string | null {
  const row = original as {
    cnpj_masked?: string
    root_cnpj_masked?: string
    cnpj?: string
    root_cnpj?: string
  }
  if (row.cnpj_masked || row.root_cnpj_masked) {
    return row.cnpj_masked || row.root_cnpj_masked || null
  }
  const raw = row.cnpj || row.root_cnpj
  return raw ? formatCnpj(raw) : null
}

const openMap = ref<Record<string, boolean>>({})

watch(() => props.rows, () => {
  openMap.value = {}
}, { deep: false })
</script>

<template>
  <div
    class="flex min-w-0 flex-col gap-3"
    :data-testid="testId"
  >
    <template v-if="loading && !rows.length">
      <UCard
        v-for="n in 3"
        :key="`sk-${n}`"
        :ui="{ body: 'space-y-3 p-3 sm:p-4' }"
      >
        <USkeleton class="h-4 w-2/3" />
        <USkeleton class="h-3 w-1/3" />
        <USkeleton class="h-8 w-full" />
      </UCard>
    </template>

    <div
      v-else-if="!loading && !rows.length"
      class="py-10"
    >
      <slot name="empty">
        <ShellListEmpty
          :kind="error ? 'error' : emptyKind"
          :title="emptyTitle"
          :description="emptyDescription"
          :error="error"
          @retry="emit('retry')"
        />
      </slot>
    </div>

    <UCard
      v-for="(row, rowIndex) in rows"
      :key="getRowId(row, rowIndex)"
      :ui="{
        root: 'overflow-hidden',
        body: 'p-0'
      }"
      :data-testid="`${testId}-card-${getRowId(row, rowIndex)}`"
    >
      <div class="flex items-start gap-2.5 border-b border-default px-3 py-3">
        <UCheckbox
          v-if="selectionEnabled"
          class="mt-0.5 shrink-0"
          :model-value="isSelected(getRowId(row, rowIndex))"
          :aria-label="`Selecionar ${identityFallback(row)}`"
          @update:model-value="toggleSelected(getRowId(row, rowIndex), $event)"
        />

        <div class="min-w-0 flex-1">
          <template v-if="primaryColumn && hasCellSlot(resolvedPrimaryId)">
            <slot
              :name="`${resolvedPrimaryId}-cell`"
              :row="mockRow(row, rowIndex)"
            />
          </template>
          <CellHost
            v-else-if="primaryColumn"
            :column="primaryColumn"
            :original="row"
            :index="rowIndex"
          />
          <div
            v-else
            class="min-w-0"
          >
            <p class="truncate font-medium text-highlighted">
              {{ identityFallback(row) }}
            </p>
            <p
              v-if="cnpjFallback(row)"
              class="text-xs text-muted tabular-nums"
            >
              {{ cnpjFallback(row) }}
            </p>
          </div>
        </div>

        <div
          v-if="statusColumn"
          class="shrink-0"
        >
          <template v-if="hasCellSlot(resolvedStatusId)">
            <slot
              :name="`${resolvedStatusId}-cell`"
              :row="mockRow(row, rowIndex)"
            />
          </template>
          <CellHost
            v-else
            :column="statusColumn"
            :original="row"
            :index="rowIndex"
          />
        </div>
      </div>

      <div
        v-if="summaryColumns.length"
        class="grid gap-2 border-b border-default px-3 py-2.5"
      >
        <div
          v-for="summaryColumn in summaryColumns"
          :key="`sum-${columnId(summaryColumn)}`"
          class="flex min-w-0 items-start justify-between gap-3"
        >
          <span class="shrink-0 text-xs text-muted">
            {{ columnLabel(summaryColumn) }}
          </span>
          <div class="min-w-0 text-right">
            <template v-if="hasCellSlot(columnId(summaryColumn))">
              <slot
                :name="`${columnId(summaryColumn)}-cell`"
                :row="mockRow(row, rowIndex)"
              />
            </template>
            <CellHost
              v-else
              :column="summaryColumn"
              :original="row"
              :index="rowIndex"
            />
          </div>
        </div>
      </div>

      <UCollapsible
        v-if="detailColumns.length"
        v-model:open="openMap[getRowId(row, rowIndex)]"
        class="border-b border-default"
        :unmount-on-hide="false"
      >
        <UButton
          class="group w-full justify-between rounded-none px-3 py-2"
          color="neutral"
          variant="ghost"
          size="sm"
          :label="openMap[getRowId(row, rowIndex)] ? 'Ocultar detalhes' : 'Mais detalhes'"
          :trailing-icon="openMap[getRowId(row, rowIndex)]
            ? 'i-lucide-chevron-up'
            : 'i-lucide-chevron-down'"
          :ui="{
            base: 'font-normal text-muted',
            trailingIcon: 'size-4 transition-transform'
          }"
          :data-testid="`${testId}-toggle`"
        />

        <template #content>
          <div class="grid gap-2.5 px-3 pb-3">
            <div
              v-for="detailColumn in detailColumns"
              :key="`det-${columnId(detailColumn)}`"
              class="flex min-w-0 flex-col gap-1 border-t border-default pt-2 first:border-t-0 first:pt-0"
            >
              <span class="text-xs text-muted">
                {{ columnLabel(detailColumn) }}
              </span>
              <div class="min-w-0">
                <template v-if="hasCellSlot(columnId(detailColumn))">
                  <slot
                    :name="`${columnId(detailColumn)}-cell`"
                    :row="mockRow(row, rowIndex)"
                  />
                </template>
                <CellHost
                  v-else
                  :column="detailColumn"
                  :original="row"
                  :index="rowIndex"
                />
              </div>
            </div>
          </div>
        </template>
      </UCollapsible>

      <div
        v-if="actionColumns.length"
        class="flex flex-wrap items-center gap-1 px-2 py-2"
        :data-testid="`${testId}-actions`"
      >
        <div
          v-for="actionColumn in actionColumns"
          :key="`act-${columnId(actionColumn)}`"
          class="min-w-0"
        >
          <template v-if="hasCellSlot(columnId(actionColumn))">
            <slot
              :name="`${columnId(actionColumn)}-cell`"
              :row="mockRow(row, rowIndex)"
            />
          </template>
          <CellHost
            v-else
            :column="actionColumn"
            :original="row"
            :index="rowIndex"
          />
        </div>
      </div>
    </UCard>
  </div>
</template>
