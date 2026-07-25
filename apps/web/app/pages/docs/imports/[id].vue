<script setup lang="ts">
/**
 * Detalhe de lote de importação — polling controlado, itens filtráveis, CSV e retry UNMATCHED.
 * Arquétipo: settings + customers table.
 */
import type { TableColumn } from '@nuxt/ui'
import { documentKindLabelFromModel } from '~/utils/document-kinds'
import {
  TABLE_CELL_BADGE_CLASS,
  TABLE_CELL_BADGE_UI
} from '~/utils/table-ui'
import ShellDataTable from '~/components/shell/DataTable.vue'
import {
  LIST_FILTER_ACTIONS_ROW,
  LIST_FILTER_TOOLBAR_STACK
} from '~/utils/list-filter-layout'

const api = useApi()
const route = useRoute()
const toast = useToast()
const { canImportDocuments, sessionEpoch } = useDashboard()

const publicId = computed(() => String(route.params.id || ''))

const batch = ref<Record<string, unknown> | null>(null)
const items = ref<Array<Record<string, unknown>>>([])
const loading = ref(false)
const itemsLoading = ref(false)
const loadError = ref<string | null>(null)
const pollError = ref<string | null>(null)
const page = ref(1)
const perPage = ref(20)
const lastPage = ref(1)
const total = ref(0)
const statusFilter = ref('all')
const retrying = ref<number | null>(null)
let pollTimer: ReturnType<typeof setInterval> | null = null
let batchLoadSeq = 0
let itemsLoadSeq = 0

const itemsFeed = {
  reset() {
    items.value = []
    page.value = 1
    lastPage.value = 1
    total.value = 0
    itemsLoading.value = false
  }
}

function contextKey(epoch = sessionEpoch.value, requestedPublicId = publicId.value) {
  return `${epoch}:${requestedPublicId}`
}

function isCurrentContext(context: string, epoch: number, requestedPublicId: string) {
  return context === contextKey()
    && epoch === sessionEpoch.value
    && requestedPublicId === publicId.value
}

const statusFilterItems = [
  { label: 'Todos os resultados', value: 'all' },
  { label: 'Importados', value: 'IMPORTED' },
  { label: 'Duplicados', value: 'DUPLICATE' },
  { label: 'Sem vínculo (UNMATCHED)', value: 'UNMATCHED' },
  { label: 'Cliente divergente', value: 'CLIENT_MISMATCH' },
  { label: 'Inválidos', value: 'INVALID' },
  { label: 'Não suportados', value: 'UNSUPPORTED' },
  { label: 'Quarentena', value: 'QUARANTINED' },
  { label: 'Falhos', value: 'FAILED' },
  { label: 'Pendentes', value: 'PENDING' }
]

const columns: TableColumn<Record<string, unknown>>[] = [
  { accessorKey: 'item_index', header: '#' },
  { accessorKey: 'source_name', header: 'Arquivo' },
  { accessorKey: 'status', header: 'Resultado' },
  {
    accessorKey: 'access_key',
    header: 'Chave',
    meta: { class: { th: 'hidden lg:table-cell', td: 'hidden lg:table-cell' } }
  },
  {
    accessorKey: 'issuer_cnpj',
    header: 'Emitente',
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  {
    accessorKey: 'result_message',
    header: 'Motivo',
    meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
  },
  { id: 'actions', header: '' }
]

const isTerminal = computed(() => !!batch.value?.is_terminal || !!batch.value?.processing_complete)

function statusColor(status: string): 'success' | 'warning' | 'error' | 'info' | 'neutral' {
  if (status === 'IMPORTED' || status === 'COMPLETED' || status === 'DUPLICATE') return 'success'
  if (status === 'UNMATCHED' || status === 'COMPLETED_WITH_ERRORS' || status === 'CLIENT_MISMATCH') return 'warning'
  if (status === 'FAILED' || status === 'INVALID' || status === 'QUARANTINED') return 'error'
  if (status === 'PROCESSING' || status === 'QUEUED' || status === 'PENDING') return 'info'
  return 'neutral'
}

function canRetry(row: Record<string, unknown>) {
  if (!canImportDocuments.value) return false
  const status = String(row.status || '')
  // Só UNMATCHED e falhas transitórias — nunca conflito/inválido cegamente
  return status === 'UNMATCHED' || (status === 'FAILED' && row.result_code !== 'BYTES_CONFLICT')
}

async function loadBatch(silent = false) {
  const seq = ++batchLoadSeq
  const epoch = sessionEpoch.value
  const requestedPublicId = publicId.value
  const context = contextKey(epoch, requestedPublicId)
  if (!silent) loading.value = true
  try {
    const res = await api.documents.importBatchGet(requestedPublicId)
    if (seq !== batchLoadSeq || epoch !== sessionEpoch.value) return
    if (!isCurrentContext(context, epoch, requestedPublicId)) return
    batch.value = res.data
    if (!silent) loadError.value = null
    pollError.value = null
  } catch (caught) {
    if (seq !== batchLoadSeq || epoch !== sessionEpoch.value) return
    if (!isCurrentContext(context, epoch, requestedPublicId)) return
    const msg = apiErrorMessage(caught, 'Lote não encontrado ou inacessível.')
    if (silent && batch.value) {
      pollError.value = msg
    } else {
      batch.value = null
      itemsLoadSeq += 1
      itemsFeed.reset()
      loadError.value = msg
    }
  } finally {
    if (!silent && seq === batchLoadSeq && isCurrentContext(context, epoch, requestedPublicId)) {
      loading.value = false
    }
  }
}

async function loadItems() {
  const seq = ++itemsLoadSeq
  const epoch = sessionEpoch.value
  const requestedPublicId = publicId.value
  const context = contextKey(epoch, requestedPublicId)
  itemsLoading.value = true
  try {
    const res = await api.documents.importBatchItems(requestedPublicId, {
      page: page.value,
      per_page: perPage.value,
      ...(statusFilter.value !== 'all' ? { status: statusFilter.value } : {})
    })
    if (seq !== itemsLoadSeq || epoch !== sessionEpoch.value) return
    if (!isCurrentContext(context, epoch, requestedPublicId)) return
    items.value = res.data || []
    lastPage.value = Number(res.meta?.last_page || 1)
    total.value = Number(res.meta?.total || items.value.length)
  } catch (caught) {
    if (seq !== itemsLoadSeq || epoch !== sessionEpoch.value) return
    if (!isCurrentContext(context, epoch, requestedPublicId)) return
    toast.add({ title: apiErrorMessage(caught, 'Falha ao carregar itens.'), color: 'error' })
  } finally {
    if (seq === itemsLoadSeq && isCurrentContext(context, epoch, requestedPublicId)) {
      itemsLoading.value = false
    }
  }
}

async function retryItem(row: Record<string, unknown>) {
  if (!canRetry(row) || retrying.value) return
  const id = Number(row.id)
  const epoch = sessionEpoch.value
  const requestedPublicId = publicId.value
  const context = contextKey(epoch, requestedPublicId)
  retrying.value = id
  try {
    await api.documents.importBatchRetryItem(requestedPublicId, id)
    if (!isCurrentContext(context, epoch, requestedPublicId)) return
    toast.add({
      title: 'Retentativa enfileirada',
      description: 'Cadastre o estabelecimento do emitente se ainda estiver UNMATCHED.',
      color: 'success'
    })
    await Promise.all([loadBatch(true), loadItems()])
  } catch (caught) {
    if (!isCurrentContext(context, epoch, requestedPublicId)) return
    toast.add({ title: apiErrorMessage(caught, 'Retry não permitido para este item.'), color: 'error' })
  } finally {
    if (isCurrentContext(context, epoch, requestedPublicId)) retrying.value = null
  }
}

async function downloadCsv() {
  try {
    const url = api.documents.importBatchCsvUrl(publicId.value)
    // Navegação com cookie de sessão — sem embutir XML no DOM
    window.open(url, '_blank', 'noopener')
  } catch {
    toast.add({ title: 'Não foi possível abrir o CSV', color: 'error' })
  }
}

function stopPoll() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

function startPoll() {
  stopPoll()
  if (isTerminal.value) return
  // Polling controlado: 4s, preserva último estado em falha
  pollTimer = setInterval(() => {
    if (document.hidden) return
    void loadBatch(true).then(() => {
      if (isTerminal.value) {
        stopPoll()
        void loadItems()
      }
    })
  }, 4000)
}

async function reload() {
  await Promise.all([loadBatch(), loadItems()])
  startPoll()
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
  void loadItems()
}

watch([page, statusFilter], () => {
  void loadItems()
})

watch([publicId, sessionEpoch], () => {
  batchLoadSeq += 1
  batch.value = null
  itemsLoadSeq += 1
  itemsFeed.reset()
  loadError.value = null
  pollError.value = null
  retrying.value = null
  stopPoll()
  void reload()
}, { flush: 'sync' })

watch(isTerminal, (done) => {
  if (done) stopPoll()
  else startPoll()
})

onMounted(() => void reload())

onBeforeUnmount(() => {
  stopPoll()
})
</script>

<template>
  <!--
    Detalhe de lote — casca ShellPagePanel (lista/settings híbrido).
    Tabela de itens: ShellDataTable (customers.vue).
  -->
  <ShellPagePanel id="import-batch-detail">
    <template #header>
      <ShellPageNavbar :title="`Lote ${publicId.slice(0, 8)}…`">
        <template #right>
          <ShellNavbarBack
            to="/docs/imports"
            label="Histórico"
          />
          <UButton
            v-if="batch"
            color="neutral"
            variant="subtle"
            icon="i-lucide-download"
            label="CSV"
            size="sm"
            aria-label="Exportar relatório CSV do lote"
            @click="downloadCsv"
          />
          <ShellNavbarRefresh
            :loading="loading || itemsLoading"
            aria-label="Atualizar lote"
            @click="() => { loadBatch(); loadItems() }"
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
        :actions="[{ label: 'Voltar', to: '/docs/imports' }]"
      />

      <template v-else-if="batch">
        <div class="flex flex-wrap items-center gap-2" role="status" aria-live="polite">
          <UBadge :color="statusColor(String(batch.status))" variant="subtle" size="lg">
            {{ batch.status }}
          </UBadge>
          <UBadge
            v-if="batch.upload_complete"
            color="success"
            variant="outline"
          >
            Upload ok
          </UBadge>
          <UBadge
            v-if="batch.processing_complete"
            color="success"
            variant="outline"
          >
            Processamento ok
          </UBadge>
          <UBadge
            v-else-if="batch.upload_complete"
            color="info"
            variant="outline"
          >
            Processando…
          </UBadge>
        </div>

        <UAlert
          v-if="pollError"
          color="warning"
          icon="i-lucide-wifi-off"
          :title="pollError"
          :actions="[{ label: 'Atualizar', color: 'neutral', variant: 'subtle', onClick: () => loadBatch(true) }]"
        />

        <UProgress
          v-if="!isTerminal"
          :model-value="batch.item_count ? Math.min(100, Math.round((Number(batch.processed_count || 0) / Number(batch.item_count)) * 100)) : undefined"
          :indeterminate="!batch.item_count || !batch.processed_count"
          class="w-full"
          aria-label="Progresso do processamento do lote"
        />

        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <div>
            <dt class="text-muted">
              Arquivos
            </dt>
            <dd class="text-highlighted">
              {{ batch.file_count }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Itens
            </dt>
            <dd class="text-highlighted">
              {{ batch.item_count }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Importados
            </dt>
            <dd class="text-highlighted">
              {{ batch.imported_count }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Sem vínculo
            </dt>
            <dd class="text-highlighted">
              {{ batch.unmatched_count }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Duplicados
            </dt>
            <dd class="text-highlighted">
              {{ batch.duplicate_count }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Inválidos
            </dt>
            <dd class="text-highlighted">
              {{ batch.invalid_count }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Quarentena
            </dt>
            <dd class="text-highlighted">
              {{ batch.quarantined_count }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Falhos
            </dt>
            <dd class="text-highlighted">
              {{ batch.failed_count }}
            </dd>
          </div>
        </dl>

        <UAlert
          v-if="batch.error_message"
          color="error"
          icon="i-lucide-alert-triangle"
          :title="String(batch.error_message)"
        />

        <div
          :class="LIST_FILTER_TOOLBAR_STACK"
          data-testid="batch-items-filter-toolbar"
        >
          <div :class="LIST_FILTER_ACTIONS_ROW">
            <USelect
              v-model="statusFilter"
              :items="statusFilterItems"
              value-key="value"
              class="w-48 shrink-0"
              aria-label="Filtrar itens do lote por resultado"
            />
          </div>
        </div>

        <ShellDataTable
          test-id="batch-items-table"
          ui-preset="monitoring-compact"
          primary-column-id="source_name"
          status-column-id="status"
          :summary-column-ids="['item_index', 'access_key']"
          :columns="columns"
          :data="items"
          :loading="itemsLoading"
          :page="page"
          :total="total"
          :items-per-page="perPage"
          per-page-aria-label="Itens por página"
          @update:page="page = $event"
          @update:items-per-page="setPerPage"
        >
          <template #source_name-cell="{ row }">
            <div class="min-w-0">
              <p class="truncate text-sm">
                {{ row.original.source_name || row.original.entry_name || '—' }}
              </p>
              <p
                v-if="row.original.model || row.original.kind"
                class="text-xs text-muted"
              >
                <template v-if="documentKindLabelFromModel(row.original.model as string)">
                  {{ documentKindLabelFromModel(row.original.model as string) }}
                  · modelo {{ row.original.model }}
                </template>
                <template v-else-if="row.original.kind">
                  {{ String(row.original.kind) }}
                </template>
                <template v-else>
                  modelo {{ row.original.model }}
                </template>
              </p>
            </div>
          </template>
          <template #status-cell="{ row }">
            <UBadge
              :color="statusColor(String(row.original.status))"
              variant="subtle"
              size="md"
              :class="TABLE_CELL_BADGE_CLASS"
              :ui="TABLE_CELL_BADGE_UI"
            >
              {{ statusLabel(String(row.original.status)) }}
            </UBadge>
          </template>
          <template #access_key-cell="{ row }">
            <span class="font-mono text-xs">
              {{ row.original.access_key ? String(row.original.access_key).slice(0, 14) + '…' : '—' }}
            </span>
          </template>
          <template #result_message-cell="{ row }">
            <span class="line-clamp-2 text-xs text-muted">
              {{ row.original.result_message || row.original.result_code || '—' }}
            </span>
          </template>
          <template #actions-cell="{ row }">
            <UButton
              v-if="canRetry(row.original)"
              size="xs"
              color="primary"
              variant="subtle"
              label="Tentar novamente"
              :loading="retrying === Number(row.original.id)"
              :aria-label="`Tentar novamente o item ${row.original.item_index} sem vínculo ou com falha transitória`"
              @click="retryItem(row.original)"
            />
            <UTooltip
              v-else-if="['CLIENT_MISMATCH', 'INVALID', 'UNSUPPORTED', 'QUARANTINED'].includes(String(row.original.status))"
              text="Corrija na origem ou revise em quarentena — sem aceitar cegamente"
            >
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                icon="i-lucide-ban"
                square
                disabled
                aria-label="Nova tentativa indisponível para este resultado"
              />
            </UTooltip>
          </template>
          <template #empty>
            <UEmpty
              icon="i-lucide-file-x-2"
              title="Nenhum item neste resultado"
              description="Altere o filtro ou aguarde o processamento do lote."
            />
          </template>
          <template #footer>
            <span class="tabular-nums">{{ total }}</span> item(ns)
          </template>
        </ShellDataTable>
      </template>

      <div
        v-else-if="loading"
        class="space-y-2"
        role="status"
      >
        <USkeleton class="h-8 w-1/3" />
        <USkeleton class="h-24 w-full" />
      </div>
    </template>
  </ShellPagePanel>
</template>
