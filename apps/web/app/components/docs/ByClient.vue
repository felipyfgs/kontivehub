<script setup lang="ts">
/**
 * Documentos → Por cliente — arquétipo customers.vue / lista canônica do painel.
 * Recurso: busca, filtro operacional, Exibir colunas, sort server-side,
 * refresh, loading/empty/error, carregamento incremental e ações de linha.
 * Domínio: captura/sync (sem colunas de cadastro A1/estado).
 */
import type { DropdownMenuItem, TableColumn } from '@nuxt/ui'
import type { Client } from '~/types/api'
import { upperFirst } from 'scule'
import ShellDataTable from '~/components/shell/DataTable.vue'
import { laravelPageBatch, usePagedTable } from '~/composables/usePagedTable'
import { sortHeader } from '~/utils/table-sort'
import {
  TABLE_CELL_BADGE_CLASS,
  TABLE_CELL_BADGE_UI
} from '~/utils/table-ui'
import { formatCnpj, truncateText } from '~/utils/format'
import {
  COMPACT_BUTTON_LABEL_UI,
  LIST_FILTER_ACTIONS_ROW,
  LIST_FILTER_SEARCH_INPUT,
  LIST_FILTER_TOOLBAR_STACK
} from '~/utils/list-filter-layout'

const search = defineModel<string>('search', { default: '' })
const operationalFilter = defineModel<string>('operationalFilter', { default: 'total' })

const route = useRoute()
const router = useRouter()

function hydrateByClientSortingFromQuery() {
  const raw = String(route.query.sort || 'legal_name')
  const id = raw === 'cnpj' ? 'cnpj' : 'legal_name'
  const desc = String(route.query.sort_direction ?? route.query.direction ?? 'asc') === 'desc'
  return [{ id, desc }] as { id: string, desc: boolean }[]
}

const sorting = ref<{ id: string, desc: boolean }[]>(hydrateByClientSortingFromQuery())

async function syncByClientSortUrl() {
  const sort = sorting.value[0]
  const sortId = sort?.id === 'cnpj' ? 'cnpj' : 'legal_name'
  const nextQuery: Record<string, string | string[] | null | undefined> = {
    ...route.query,
    sort: sortId,
    sort_direction: sort?.desc ? 'desc' : 'asc'
  }
  // Defaults omitidos (legal_name + asc)
  if (sortId === 'legal_name' && !sort?.desc) {
    delete nextQuery.sort
    delete nextQuery.sort_direction
    delete nextQuery.direction
  }
  await router.replace({ path: route.path, query: nextQuery })
}

const emit = defineEmits<{
  openClient: [client: Client]
  totalChange: [total: number]
  loadingChange: [loading: boolean]
}>()

const api = useApi()
const table = useTemplateRef<{ tableApi?: {
  getAllColumns: () => Array<{
    id: string
    getCanHide: () => boolean
    getIsVisible: () => boolean
    getColumn: (id: string) => { toggleVisibility: (v: boolean) => void } | undefined
  }>
  getColumn: (id: string) => { toggleVisibility: (v: boolean) => void } | undefined
} } | null>('table')
const columnVisibility = ref()
const toast = useToast()

const operationalItems = [
  { label: 'Todos', value: 'total' },
  { label: 'Captura com problema', value: 'capture_problem' }
]

const clientsFeed = usePagedTable<Client>({
  getKey: client => client.id,
  pageSize: 20,
  load: async ({ page, pageSize }) => {
    await syncByClientSortUrl()
    const sort = sorting.value[0]
    const response = await api.clients.list({
      page,
      per_page: pageSize,
      q: search.value.trim() || undefined,
      operational_filter: operationalFilter.value === 'total'
        ? undefined
        : operationalFilter.value as 'capture_problem',
      sort: sort?.id === 'cnpj' ? 'cnpj' : 'legal_name',
      direction: sort?.desc ? 'desc' : 'asc'
    })

    return laravelPageBatch(response)
  }
})

const clientsFeedTotal = clientsFeed.total
const clientsFeedPage = clientsFeed.page
const clientsFeedPageSize = clientsFeed.pageSize

const rows = clientsFeed.rows
const loading = clientsFeed.pendingInitial
const loadError = computed(() => clientsFeed.error.value
  ? apiErrorMessage(clientsFeed.error.value, 'Erro ao listar clientes.')
  : null)

watch(clientsFeed.total, (value) => {
  if (typeof value === 'number') emit('totalChange', value)
})

watch(clientsFeed.pending, value => emit('loadingChange', value), { immediate: true })

type ChipTone = 'success' | 'warning' | 'error' | 'neutral' | 'info'

const columns: TableColumn<Client>[] = [
  {
    accessorKey: 'legal_name',
    header: ({ column }) => sortHeader('Cliente', column),
    enableHiding: false,
    meta: {
      class: {
        th: 'min-w-40 w-full',
        td: 'min-w-40 w-full overflow-hidden'
      }
    }
  },
  {
    id: 'cnpj',
    accessorFn: row => row.cnpj || row.root_cnpj,
    header: ({ column }) => sortHeader('CNPJ/CPF', column),
    meta: {
      class: {
        th: 'hidden sm:table-cell w-40 min-w-36',
        td: 'hidden sm:table-cell w-40 min-w-36'
      }
    }
  },
  {
    id: 'capture',
    accessorFn: row => row.capture_summary?.status || '',
    header: 'Captura',
    enableSorting: false,
    meta: {
      class: {
        th: 'w-32 min-w-28',
        td: 'w-32 min-w-28'
      }
    }
  },
  {
    id: 'sync',
    accessorFn: row => row.sync_summary?.status || '',
    header: 'Sync',
    enableSorting: false,
    meta: {
      class: {
        th: 'hidden md:table-cell w-32 min-w-28',
        td: 'hidden md:table-cell w-32 min-w-28'
      }
    }
  },
  {
    id: 'actions',
    header: 'Ações',
    enableSorting: false,
    enableHiding: false,
    meta: {
      class: {
        th: 'w-28 min-w-24',
        td: 'w-28 min-w-24'
      }
    }
  }
]

function captureInfo(client: Client): { chipLabel: string, color: ChipTone } {
  const summary = client.capture_summary
  if (!summary || summary.status === 'NONE') {
    return { chipLabel: 'Sem est.', color: 'neutral' }
  }
  if (summary.status === 'ON') {
    return { chipLabel: 'Captura on', color: 'success' }
  }
  if (summary.status === 'PARTIAL') {
    return { chipLabel: 'Parcial', color: 'warning' }
  }
  return { chipLabel: 'Captura off', color: 'neutral' }
}

function syncInfo(client: Client): { chipLabel: string, color: ChipTone, title?: string } {
  const summary = client.sync_summary
  if (!summary || !summary.has_cursor || summary.status === 'NONE') {
    return { chipLabel: 'Sem cursor', color: 'neutral' }
  }
  const last = summary.last_success_at
    ? `Último sucesso: ${formatDateTime(summary.last_success_at)}`
    : undefined
  switch (summary.status) {
    case 'BLOCKED':
      return { chipLabel: 'Bloqueado', color: 'error', title: last }
    case 'ERROR':
      return { chipLabel: 'Erro', color: 'error', title: last }
    case 'RUNNING':
      return { chipLabel: 'Em execução', color: 'info', title: last }
    case 'WAITING':
      return { chipLabel: 'Na fila', color: 'warning', title: last }
    case 'IDLE':
      return { chipLabel: 'OK', color: 'success', title: last }
    default:
      return { chipLabel: statusLabel(summary.status), color: 'neutral', title: last }
  }
}

async function copyCnpj(value?: string | null) {
  const clean = normalizeCnpj(value)
  if (!clean) return
  try {
    await navigator.clipboard.writeText(clean)
    toast.add({ title: 'CNPJ copiado', description: clean, color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível copiar o CNPJ', color: 'error' })
  }
}

function rowActions(client: Client): DropdownMenuItem[][] {
  return [[{
    label: 'Ver documentos',
    icon: 'i-lucide-file-text',
    onSelect: () => emit('openClient', client)
  }, {
    label: 'Copiar CNPJ/CPF',
    icon: 'i-lucide-copy',
    onSelect: () => void copyCnpj(client.cnpj || client.root_cnpj)
  }]]
}

function onSearchEnter() {
  void reload()
}

function onOperationalChange() {
  void reload()
}

function clearSearch() {
  search.value = ''
  operationalFilter.value = 'total'
  void reload()
}

async function reload() {
  await clientsFeed.resetAndLoad()
}

watch(sorting, () => void reload(), { deep: true })

defineExpose({ reload })
</script>

<template>
  <!-- Padrão ouro clients/index.vue: busca esq · filtros/Exibir/refresh dir -->
  <div class="flex min-h-0 w-full flex-col gap-4 sm:gap-5" data-testid="notes-by-client">
    <ShellStickyTableFilters>
      <div :class="LIST_FILTER_TOOLBAR_STACK">
        <UInput
          v-model="search"
          :class="LIST_FILTER_SEARCH_INPUT"
          icon="i-lucide-search"
          placeholder="Filtrar por nome ou CNPJ/CPF..."
          aria-label="Filtrar clientes por nome ou CNPJ/CPF"
          @keydown.enter.prevent="onSearchEnter"
        />

        <div :class="LIST_FILTER_ACTIONS_ROW">
          <USelect
            v-model="operationalFilter"
            :items="operationalItems"
            value-key="value"
            :ui="{ trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200' }"
            class="min-w-36 shrink-0 sm:min-w-40"
            aria-label="Filtro de captura"
            @update:model-value="onOperationalChange"
          />
          <UDropdownMenu
            :items="
              table?.tableApi
                ?.getAllColumns()
                .filter((column: any) => column.getCanHide())
                .map((column: any) => ({
                  label: ({
                    legal_name: 'Cliente',
                    cnpj: 'CNPJ/CPF',
                    capture: 'Captura',
                    sync: 'Sync',
                    actions: 'Ações'
                  } as Record<string, string>)[column.id] || upperFirst(column.id),
                  type: 'checkbox' as const,
                  checked: column.getIsVisible(),
                  onUpdateChecked(checked: boolean) {
                    table?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
                  },
                  onSelect(e?: Event) {
                    e?.preventDefault()
                  }
                }))
            "
            :content="{ align: 'end' }"
          >
            <UButton
              label="Colunas"
              color="neutral"
              variant="outline"
              trailing-icon="i-lucide-settings-2"
              aria-label="Exibir colunas"
              :ui="COMPACT_BUTTON_LABEL_UI"
            />
          </UDropdownMenu>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            square
            aria-label="Atualizar lista"
            :loading="clientsFeed.pending.value"
            @click="reload"
          />
        </div>
      </div>
    </ShellStickyTableFilters>

    <UAlert
      v-if="loadError"
      color="warning"
      variant="subtle"
      icon="i-lucide-wifi-off"
      :title="loadError"
      :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: clientsFeed.retry }]"
    />

    <ShellDataTable
      ref="table"
      v-model:column-visibility="columnVisibility"
      v-model:sorting="sorting"
      ui-preset="dashboard"
      test-id="data-table"
      primary-column-id="legal_name"
      status-column-id="capture"
      :summary-column-ids="['cnpj', 'sync']"
      :data="rows"
      :columns="columns"
      :loading="loading"
      :page="clientsFeedPage"
      :total="clientsFeedTotal"
      :items-per-page="clientsFeedPageSize"
      :manual-sorting="true"
      :error="loadError"
      empty-title="Nenhum cliente encontrado"
      empty-description="Ajuste a busca ou o filtro de captura."
      per-page-aria-label="Clientes por página"
      @update:page="(p) => clientsFeed.setPage(p)"
      @update:items-per-page="(n) => clientsFeed.setPageSize(n)"
      @retry="clientsFeed.retry"
    >
      <template #legal_name-cell="{ row }">
        <div class="min-w-0 max-w-xs">
          <button
            type="button"
            class="block w-full truncate text-left font-medium text-highlighted hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :title="row.original.display_name
              ? `${row.original.legal_name || row.original.name} · ${row.original.display_name}`
              : (row.original.legal_name || row.original.name)"
            @click="emit('openClient', row.original)"
          >
            {{ truncateText(row.original.legal_name || row.original.name, 40) || row.original.legal_name || row.original.name || '—' }}
          </button>
          <p
            v-if="row.original.display_name"
            class="truncate text-xs text-muted"
            :title="row.original.display_name"
          >
            {{ truncateText(row.original.display_name, 40) }}
          </p>
          <p class="mt-0.5 font-mono text-xs text-dimmed sm:hidden">
            {{ formatCnpj(row.original.cnpj || row.original.root_cnpj) }}
          </p>
        </div>
      </template>

      <template #cnpj-cell="{ row }">
        <button
          type="button"
          class="group inline-flex w-full max-w-full items-center gap-1.5 font-mono text-highlighted hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :title="`Copiar ${normalizeCnpj(row.original.cnpj || row.original.root_cnpj)}`"
          @click.stop="copyCnpj(row.original.cnpj || row.original.root_cnpj)"
        >
          <span class="min-w-0 truncate">{{ formatCnpj(row.original.cnpj || row.original.root_cnpj) }}</span>
          <UIcon
            name="i-lucide-copy"
            class="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-70"
            aria-hidden="true"
          />
        </button>
      </template>

      <template #capture-cell="{ row }">
        <UBadge
          v-for="info in [captureInfo(row.original)]"
          :key="`cap-${row.original.id}-${info.chipLabel}`"
          :color="info.color"
          variant="soft"
          size="md"
          :class="TABLE_CELL_BADGE_CLASS"
          :ui="TABLE_CELL_BADGE_UI"
        >
          {{ info.chipLabel }}
        </UBadge>
      </template>

      <template #sync-cell="{ row }">
        <UBadge
          v-for="info in [syncInfo(row.original)]"
          :key="`sync-${row.original.id}-${info.chipLabel}`"
          :color="info.color"
          variant="soft"
          size="md"
          :class="TABLE_CELL_BADGE_CLASS"
          :ui="TABLE_CELL_BADGE_UI"
          :title="info.title || info.chipLabel"
        >
          {{ info.chipLabel }}
        </UBadge>
      </template>

      <template #actions-cell="{ row }">
        <div class="flex items-center justify-end gap-1.5">
          <UButton
            icon="i-lucide-file-text"
            color="primary"
            variant="soft"
            size="sm"
            square
            class="size-8"
            :aria-label="`Documentos de ${row.original.legal_name || row.original.name}`"
            @click="emit('openClient', row.original)"
          />
          <span
            class="h-5 w-px shrink-0 bg-accented"
            aria-hidden="true"
          />
          <UDropdownMenu
            :content="{ align: 'end' }"
            :items="rowActions(row.original)"
          >
            <UButton
              icon="i-lucide-ellipsis-vertical"
              color="neutral"
              variant="soft"
              size="sm"
              square
              class="size-8"
              :aria-label="`Mais ações de ${row.original.legal_name || row.original.name}`"
            />
          </UDropdownMenu>
        </div>
      </template>
      <template #footer>
        <span class="tabular-nums">{{ clientsFeedTotal }}</span> cliente(s)
      </template>
      <template #empty>
        <UEmpty
          icon="i-lucide-building-2"
          title="Nenhum cliente encontrado"
          description="Ajuste a busca ou o filtro de captura."
        >
          <UButton
            v-if="search || operationalFilter !== 'total'"
            label="Limpar filtros"
            color="neutral"
            variant="outline"
            @click="clearSearch"
          />
        </UEmpty>
      </template>
    </ShellDataTable>
  </div>
</template>
