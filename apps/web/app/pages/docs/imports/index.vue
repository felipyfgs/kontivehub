<script setup lang="ts">
/**
 * Histórico de lotes de importação XML/ZIP.
 * Arquétipo: customers.vue (lista + tabela) — adaptado a batches.
 */
import type { TableColumn, TableRow } from '@nuxt/ui'
import {
  TABLE_CELL_BADGE_CLASS,
  TABLE_CELL_BADGE_UI
} from '~/utils/table-ui'
import ShellDataTable from '~/components/shell/DataTable.vue'

const api = useApi()
const router = useRouter()
const toast = useToast()
const { sessionEpoch, canImportDocuments } = useDashboard()

const items = ref<Array<Record<string, unknown>>>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const page = ref(1)
const perPage = ref(20)
const lastPage = ref(1)
const total = ref(0)

const columns: TableColumn<Record<string, unknown>>[] = [
  { accessorKey: 'id', header: 'Lote' },
  { accessorKey: 'status', header: 'Status' },
  {
    accessorKey: 'file_count',
    header: 'Arquivos',
    meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
  },
  {
    accessorKey: 'processed_count',
    header: 'Processados',
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  {
    accessorKey: 'imported_count',
    header: 'Importados',
    meta: { class: { th: 'hidden lg:table-cell', td: 'hidden lg:table-cell' } }
  },
  {
    accessorKey: 'created_at',
    header: 'Criado',
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  { id: 'actions', header: '' }
]

function statusColor(status: string): 'success' | 'warning' | 'error' | 'info' | 'neutral' {
  if (status === 'COMPLETED') return 'success'
  if (status === 'COMPLETED_WITH_ERRORS') return 'warning'
  if (status === 'FAILED') return 'error'
  if (status === 'PROCESSING' || status === 'QUEUED') return 'info'
  return 'neutral'
}

async function load() {
  const epoch = sessionEpoch.value
  loading.value = true
  try {
    const res = await api.documents.importBatches({ page: page.value, per_page: perPage.value })
    if (epoch !== sessionEpoch.value) return
    items.value = res.data || []
    lastPage.value = Number(res.meta?.last_page || 1)
    total.value = Number(res.meta?.total || items.value.length)
    loadError.value = null
  } catch (caught) {
    if (epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(caught, 'Erro ao carregar lotes de importação.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (epoch === sessionEpoch.value) loading.value = false
  }
}

function openBatch(row: Record<string, unknown>) {
  const id = String(row.public_id || row.id || '')
  if (!id) return
  void router.push(`/docs/imports/${encodeURIComponent(id)}`)
}

function selectRow(_event: Event, row: TableRow<Record<string, unknown>>) {
  openBatch(row.original)
}

function setPerPage(next: number) {
  const allowed = [10, 20, 50]
  const target = allowed.includes(Number(next)) ? Number(next) : 20
  if (perPage.value === target) return
  perPage.value = target
  if (page.value !== 1) {
    page.value = 1
    return
  }
  void load()
}

watch(page, () => {
  void load()
})

watch(sessionEpoch, () => {
  items.value = []
  page.value = 1
  total.value = 0
  loadError.value = null
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <!--
    Arquétipo lista admin (customers.vue) via ShellPagePanel.
    Empty: UEmpty (Nuxt UI) · tabela: ShellDataTable · paginação no footer.
  -->
  <ShellPagePanel id="import-batches">
    <template #header>
      <ShellPageNavbar title="Importações XML/ZIP">
        <template #right>
          <UButton
            v-if="canImportDocuments"
            icon="i-lucide-upload"
            label="Nova importação"
            to="/docs?import=1"
          />
          <ShellNavbarRefresh
            :loading="loading"
            aria-label="Atualizar histórico de lotes"
            @click="load"
          />
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="loadError"
        color="error"
        icon="i-lucide-wifi-off"
        :title="loadError"
        :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
      />

      <ShellDataTable
        v-if="loading || items.length || !loadError"
        test-id="data-table"
        ui-preset="monitoring-compact"
        primary-column-id="id"
        status-column-id="status"
        :summary-column-ids="['file_count', 'processed_count', 'imported_count', 'created_at']"
        :columns="columns"
        :data="items"
        :loading="loading"
        :page="page"
        :total="total"
        :items-per-page="perPage"
        per-page-aria-label="Lotes por página"
        @update:page="page = $event"
        @update:items-per-page="setPerPage"
        @select="selectRow"
      >
        <template #id-cell="{ row }">
          <span class="font-mono text-xs">
            {{ String(row.original.public_id || row.original.id || '').slice(0, 12) }}…
          </span>
        </template>
        <template #status-cell="{ row }">
          <div
            class="flex w-full min-w-0 items-center gap-1"
            :class="row.original.upload_complete && !row.original.processing_complete ? '' : undefined"
          >
            <UBadge
              :color="statusColor(String(row.original.status))"
              variant="subtle"
              size="md"
              :class="TABLE_CELL_BADGE_CLASS"
              :ui="TABLE_CELL_BADGE_UI"
            >
              {{ statusLabel(String(row.original.status)) }}
            </UBadge>
            <UBadge
              v-if="row.original.upload_complete && !row.original.processing_complete"
              color="info"
              variant="outline"
              size="sm"
              class="shrink-0"
            >
              Processando
            </UBadge>
          </div>
        </template>
        <template #created_at-cell="{ row }">
          {{ row.original.created_at ? formatDateTime(String(row.original.created_at)) : '—' }}
        </template>
        <template #actions-cell="{ row }">
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-eye"
            square
            aria-label="Abrir detalhe do lote"
            @click.stop="openBatch(row.original)"
          />
        </template>
        <template #empty>
          <UEmpty
            v-if="!loadError"
            icon="i-lucide-upload"
            title="Nenhum lote ainda"
            description="Importe XML/ZIP em Documentos para criar o primeiro lote."
            :actions="[{ label: 'Ir a Documentos', to: '/docs' }]"
          />
        </template>
        <template #footer>
          <span class="tabular-nums">{{ total }}</span> lote(s)
        </template>
      </ShellDataTable>
    </template>
  </ShellPagePanel>
</template>
