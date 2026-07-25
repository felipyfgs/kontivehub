<script setup lang="ts">
/**
 * Posto operacional Documentos (`/docs`) — tabela densa (customers)
 * + detalhe em modal responsivo + export. Views
 * ficam no submenu do sidebar (`navigation.ts`), não em tabs da página.
 * Fonte: .local/reference/nuxt-dashboard-template/app/pages/customers.vue
 */
import { documentKindLabel, isDocumentKindCaptureAvailable } from '~/utils/document-kinds'
import type { Client, Establishment, ExportFilters, NfseNote, NotesInsights } from '~/types/api'
import type { NoteListParams } from '~/composables/useApi'
import {
  activeTriageQueue,
  applyTriageQueue,
  catalogToExportFilters,
  emptyDocsFilters,
  FILTER_ALL,
  hasExportableCatalogFilters,
  isActiveFilterValue,
  type NotesFilterState,
  type NotesTriageQueue,
  type NotesViewMode
} from '~/utils/notes-filters'

/** Teto alinhado a BuildExportZipJob::MAX_ACCESS_KEYS */
const MAX_EXPORT_KEYS = 100

const api = useApi()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const props = withDefaults(defineProps<{
  initialView?: NotesViewMode
}>(), {
  initialView: 'client'
})
const { canCreateExport, canImportDocuments, sessionEpoch } = useDashboard()

/** Query params aceitos no catálogo (deep-links e redirect legado). */
const CATALOG_QUERY_KEYS = new Set([
  'kind',
  'direction',
  'q',
  'client_id',
  'establishment_id',
  'fiscal_role',
  'acquisition_source',
  'artifact_quality',
  'coverage_status',
  'status',
  'competence',
  'issued_from',
  'issued_to',
  'missing_party_name',
  'issuer_cnpj',
  'taker_cnpj'
])

const notes = ref<NfseNote[]>([])
/** Lista de clientes (API de cadastro) na visão Por cliente — com captura/sync. */
const byClientRows = ref<Client[]>([])
const byClientPage = ref(1)
const byClientPerPage = ref(20)
const byClientTotal = ref(0)
const byClientLastPage = ref(1)
/** Filtro operacional server-side da lista de clientes em Documentos. */
const clientOperationalFilter = ref('total')
const byClientSorting = ref<{ id: string, desc: boolean }[]>([{ id: 'legal_name', desc: false }])
const clients = ref<Client[]>([])
const establishments = ref<Establishment[]>([])
const insights = ref<NotesInsights | null>(null)
const insightsLoading = ref(false)
const nextCursor = ref<string | null>(null)
/** Total no escopo dos filtros (meta.total da API). */
const listTotal = ref(0)
/** Tamanho do lote incremental (enviado como `limit`; API 1–100). */
const pageSize = ref(25)
const loading = ref(false)
const loadError = ref<string | null>(null)
const loadingFilters = ref(false)
const exporting = ref(false)
const importOpen = ref(false)
const importing = ref(false)
const importFiles = ref<File[]>([])
const importClientId = ref<string>(FILTER_ALL)
const selectedKeys = ref<string[]>([])

const triageQueue = computed(() => activeTriageQueue(filters))

const persistedFilters = useState<NotesFilterState>('notes-workspace-filters', emptyDocsFilters)
const filters = reactive<NotesFilterState>({ ...persistedFilters.value })
const view = ref<NotesViewMode>(props.initialView)

/** Contexto CT-e (autXML, pendências) só no catálogo com filtro kind=CTE. */
const showCteContext = computed(() => view.value === 'document' && filters.kind === 'CTE')

watch(filters, (value) => {
  persistedFilters.value = { ...value }
}, { deep: true })

// Entrada profunda: /docs?import=1 ou /docs/catalog?import=1
watch(
  () => route.query.import,
  (value) => {
    if (value === '1' && canImportDocuments.value) {
      importOpen.value = true
      const { import: _drop, ...rest } = route.query
      void router.replace({ path: route.path, query: rest })
    }
  },
  { immediate: true }
)

watch(sessionEpoch, () => {
  // Mantém kind documental (ex. CTE) como preferência de visão; limpa o resto
  // tenant-scoped para não repopular CNPJ/cliente/pendências do office anterior.
  const preserveKind = filters.kind === 'CTE' ? 'CTE' : FILTER_ALL
  const empty = emptyDocsFilters()
  empty.kind = preserveKind
  Object.assign(filters, empty)
  persistedFilters.value = empty
  notes.value = []
  byClientRows.value = []
  byClientTotal.value = 0
  byClientLastPage.value = 1
  byClientPage.value = 1
  clients.value = []
  establishments.value = []
  insights.value = null
  selectedKeys.value = []
  nextCursor.value = null
  listTotal.value = 0
  loadError.value = null
  if (view.value === 'document' && !selectedAccessKey.value) {
    void syncCatalogQuery(true)
  }
  void reloadActive()
  void loadClients()
})

const selectedAccessKey = computed(() =>
  typeof route.params.accessKey === 'string' ? route.params.accessKey : null
)

const selectedPreview = computed(() =>
  notes.value.find(n => n.access_key === selectedAccessKey.value) || null
)

/** Controla o detalhe canônico pela rota. */
const isDetailOpen = computed({
  get: () => !!selectedAccessKey.value,
  set: (open: boolean) => {
    if (!open) closeDetail()
  }
})

/**
 * Disponibilidade de captura do tipo filtrado.
 * - NFSE / NFE / NFCE: operacionais (NFC-e = saída/import, não DistDFe entrada).
 * - Linha da API só confirma true; `false`/ausente não deve sobrescrever o stack local.
 */
const kindCaptureAvailable = computed(() => {
  if (filters.kind === FILTER_ALL) return true
  if (isDocumentKindCaptureAvailable(filters.kind)) return true
  const runtimeValue = notes.value.find(note => note.kind === filters.kind)?.capture_available
  return runtimeValue === true
})

const kindExportAvailable = computed(() =>
  filters.kind === FILTER_ALL
  || filters.kind === 'NFSE'
  || filters.kind === 'NFE'
  || filters.kind === 'NFCE'
  || filters.kind === 'CTE'
)

function queryParams(cursor?: string | null): NoteListParams {
  return {
    limit: pageSize.value,
    ...(isActiveFilterValue(filters.q) ? { q: filters.q } : {}),
    ...(isActiveFilterValue(filters.kind) ? { kind: filters.kind } : {}),
    ...(isActiveFilterValue(filters.direction) ? { direction: filters.direction as NoteListParams['direction'] } : {}),
    ...(isActiveFilterValue(filters.client_id) ? { client_id: Number(filters.client_id) } : {}),
    ...(isActiveFilterValue(filters.establishment_id) ? { establishment_id: Number(filters.establishment_id) } : {}),
    ...(isActiveFilterValue(filters.issuer_cnpj) ? { issuer_cnpj: filters.issuer_cnpj } : {}),
    ...(isActiveFilterValue(filters.taker_cnpj) ? { taker_cnpj: filters.taker_cnpj } : {}),
    ...(isActiveFilterValue(filters.fiscal_role) ? { fiscal_role: filters.fiscal_role as NoteListParams['fiscal_role'] } : {}),
    ...(isActiveFilterValue(filters.acquisition_source) ? { acquisition_source: filters.acquisition_source } : {}),
    ...(isActiveFilterValue(filters.artifact_quality) ? { artifact_quality: filters.artifact_quality } : {}),
    ...(isActiveFilterValue(filters.coverage_status) ? { coverage_status: filters.coverage_status } : {}),
    ...(isActiveFilterValue(filters.competence) ? { competence: filters.competence } : {}),
    ...(isActiveFilterValue(filters.issued_from) ? { issued_from: filters.issued_from } : {}),
    ...(isActiveFilterValue(filters.issued_to) ? { issued_to: filters.issued_to } : {}),
    ...(isActiveFilterValue(filters.status) ? { status: filters.status } : {}),
    ...(filters.missing_party_name === '1' ? { missing_party_name: 1 } : {}),
    ...(cursor ? { cursor } : {})
  }
}

function resetPagination() {
  nextCursor.value = null
  listTotal.value = 0
}

/** Params de insights: sem cursor/limit; mantém escopo de filtro. */
function insightsParams(): NoteListParams {
  const p = queryParams()
  delete p.limit
  delete p.cursor
  return p
}

async function reloadActive() {
  if (view.value === 'client') {
    await loadByClient()
    return
  }
  await Promise.all([load(true), loadInsights()])
}

async function loadInsights() {
  insightsLoading.value = true
  try {
    insights.value = (await api.documents.insights(insightsParams())).data
  } catch {
    // Não bloqueia o catálogo se insights falharem
    insights.value = null
  } finally {
    insightsLoading.value = false
  }
}

async function onTriageSelect(queue: NotesTriageQueue) {
  // segundo clique na mesma fila limpa
  const nextQueue = triageQueue.value === queue && queue !== 'all' ? 'all' : queue
  const label = insights.value?.competence_current_label
  Object.assign(filters, applyTriageQueue(filters, nextQueue, label))
  selectedKeys.value = []
  // Fila de documento força view catálogo (exceto "all" em por cliente)
  if (nextQueue !== 'all' && view.value !== 'document') {
    persistedFilters.value = { ...filters }
    await router.push('/docs/catalog')
    return
  }
  await reloadActive()
}

/** Seq de geração para descartar respostas stale mid-request (troca de tenant). */
let notesLoadSeq = 0
let byClientLoadSeq = 0

/** Carrega o próximo lote usando exatamente o cursor entregue pela API. */
async function load(reset = false): Promise<void> {
  if (!reset && !nextCursor.value && notes.value.length) return
  const seq = ++notesLoadSeq
  const epoch = sessionEpoch.value
  if (reset) {
    resetPagination()
    notes.value = []
    selectedKeys.value = []
  }
  loading.value = true
  try {
    const response = await api.documents.list(queryParams(reset ? null : nextCursor.value))
    if (seq !== notesLoadSeq || epoch !== sessionEpoch.value) return
    const merged = reset ? response.data : [...notes.value, ...response.data]
    notes.value = Array.from(new Map(merged.map(note => [note.access_key, note])).values())
    nextCursor.value = response.meta.next_cursor
    listTotal.value = response.meta.total
      ?? notes.value.length
    loadError.value = null
  } catch (caught) {
    if (seq !== notesLoadSeq || epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(caught, 'Erro ao listar documentos.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    if (seq === notesLoadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function onPageSizeChange(size: number) {
  const next = Math.min(100, Math.max(1, Math.floor(size)))
  if (next === pageSize.value || loading.value) return
  pageSize.value = next
  selectedKeys.value = []
  await load(true)
}

async function loadByClient() {
  const seq = ++byClientLoadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const operational = clientOperationalFilter.value
    const sort = byClientSorting.value[0]
    const sortId = sort?.id === 'cnpj' ? 'cnpj' : 'legal_name'
    const response = await api.clients.list({
      page: byClientPage.value,
      per_page: byClientPerPage.value,
      q: filters.q.trim() || undefined,
      operational_filter: operational === 'total'
        ? undefined
        : operational as 'capture_problem',
      sort: sortId,
      direction: sort?.desc ? 'desc' : 'asc'
    })
    if (seq !== byClientLoadSeq || epoch !== sessionEpoch.value) return
    byClientRows.value = response.data
    byClientTotal.value = response.meta.total
    byClientLastPage.value = response.meta.last_page
  } catch (caught) {
    if (seq !== byClientLoadSeq || epoch !== sessionEpoch.value) return
    loadError.value = apiErrorMessage(caught, 'Erro ao listar clientes.')
    toast.add({ title: loadError.value, color: 'error' })
    byClientRows.value = []
  } finally {
    if (seq === byClientLoadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function loadClients() {
  loadingFilters.value = true
  try {
    clients.value = (await api.clients.list({ per_page: 100 })).data
  } catch {
    clients.value = []
  } finally {
    loadingFilters.value = false
  }
}

async function onClientChange() {
  filters.establishment_id = FILTER_ALL
  establishments.value = []
  if (!isActiveFilterValue(filters.client_id)) return
  try {
    establishments.value = (await api.clients.get(Number(filters.client_id))).data.establishments || []
  } catch {
    establishments.value = []
  }
}

/** Query reproduzível do catálogo a partir dos filtros ativos. */
function catalogQueryFromFilters(): Record<string, string> {
  const query: Record<string, string> = {}
  for (const key of CATALOG_QUERY_KEYS) {
    const value = filters[key as keyof NotesFilterState]
    if (typeof value === 'string' && isActiveFilterValue(value)) {
      query[key] = value
    }
  }
  return query
}

/** Aplica query aceita da URL aos filtros (deep-link / redirect legado). */
function hydrateFiltersFromQuery() {
  const query = route.query
  let changed = false
  for (const key of CATALOG_QUERY_KEYS) {
    const raw = query[key]
    const value = Array.isArray(raw) ? raw[0] : raw
    if (typeof value !== 'string' || !value) continue
    if (key === 'kind' && !['NFSE', 'NFE', 'NFCE', 'CTE'].includes(value.toUpperCase())) continue
    const normalized = key === 'kind' ? value.toUpperCase() : value
    if (filters[key as keyof NotesFilterState] !== normalized) {
      ;(filters as Record<string, string>)[key] = normalized
      changed = true
    }
  }
  if (changed) {
    persistedFilters.value = { ...filters }
  }
}

/** Sincroniza filtros ativos na URL do catálogo (sem poluir detalhe por accessKey). */
async function syncCatalogQuery(replace = true) {
  if (view.value !== 'document' || selectedAccessKey.value) return
  const nextQuery = catalogQueryFromFilters()
  const current: Record<string, string> = {}
  for (const [key, raw] of Object.entries(route.query)) {
    if (!CATALOG_QUERY_KEYS.has(key)) continue
    const value = Array.isArray(raw) ? raw[0] : raw
    if (typeof value === 'string' && value) current[key] = value
  }
  const same
    = Object.keys(nextQuery).length === Object.keys(current).length
      && Object.entries(nextQuery).every(([k, v]) => current[k] === v)
  if (same && !Object.keys(route.query).some(k => !CATALOG_QUERY_KEYS.has(k) && k !== 'view')) {
    // Ainda pode haver view= legado a limpar
    if (!('view' in route.query)) return
  }
  const nav = replace ? router.replace : router.push
  await nav({ path: '/docs/catalog', query: nextQuery })
}

function resetFilters() {
  Object.assign(filters, emptyDocsFilters())
  establishments.value = []
  selectedKeys.value = []
  clientOperationalFilter.value = 'total'
  byClientPage.value = 1
  void syncCatalogQuery()
  reloadActive()
}

async function applyFilters() {
  selectedKeys.value = []
  byClientPage.value = 1
  await syncCatalogQuery()
  await reloadActive()
}

async function onCtePendingResolved() {
  await Promise.all([load(true), loadInsights()])
}

async function onByClientApply() {
  if (byClientPage.value !== 1) {
    byClientPage.value = 1
    return
  }
  await loadByClient()
}

watch(byClientPage, () => {
  void loadByClient()
})

watch(byClientSorting, () => {
  if (byClientPage.value !== 1) {
    byClientPage.value = 1
    return
  }
  void loadByClient()
}, { deep: true })

async function selectNote(note: NfseNote) {
  await router.push(`/docs/${note.access_key}`)
}

const pendingNavigationOffset = ref<-1 | 1 | null>(null)

async function selectAdjacentNote(offset: -1 | 1) {
  const current = selectedAccessKey.value
  if (!notes.value.length) {
    // /docs/catalog e /docs/[accessKey] remontam o workspace. O detalhe pode
    // aparecer antes de a lista terminar de recarregar; preserve a tecla.
    pendingNavigationOffset.value = offset
    return
  }
  const index = current
    ? notes.value.findIndex(note => note.access_key === current)
    : (offset === 1 ? -1 : notes.value.length)
  if (current && index === -1 && loading.value) {
    pendingNavigationOffset.value = offset
    return
  }
  const target = notes.value[index + offset]
  if (!target) return
  pendingNavigationOffset.value = null
  await selectNote(target)
}

watch(
  [() => notes.value.length, loading, selectedAccessKey],
  () => {
    const offset = pendingNavigationOffset.value
    if (offset === null || loading.value || !notes.value.length) return
    pendingNavigationOffset.value = null
    void selectAdjacentNote(offset)
  }
)

function onCatalogNavigationKeydown(event: KeyboardEvent) {
  if (event.altKey || event.ctrlKey || event.metaKey) return
  if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return

  const target = event.target instanceof HTMLElement ? event.target : null
  if (target?.matches('input, textarea, select, [contenteditable="true"]')) return

  event.preventDefault()
  event.stopImmediatePropagation()
  void selectAdjacentNote(event.key === 'ArrowDown' ? 1 : -1)
}

// Um único listener no workspace permanece ativo antes e durante o modal.
// A fase de captura evita a disputa entre a tabela inerte e o focus trap.
onMounted(() => window.addEventListener('keydown', onCatalogNavigationKeydown, true))
onBeforeUnmount(() => window.removeEventListener('keydown', onCatalogNavigationKeydown, true))

async function closeDetail() {
  await router.replace({ path: '/docs/catalog', query: catalogQueryFromFilters() })
}

async function openClientNotes(client: Client) {
  filters.client_id = String(client.id)
  filters.establishment_id = FILTER_ALL
  selectedKeys.value = []
  await onClientChange()
  persistedFilters.value = { ...filters }
  await router.push('/docs/catalog')
}

function buildExportFiltersFromCatalog(): ExportFilters {
  return catalogToExportFilters(filters) as ExportFilters
}

const importTotalBytes = computed(() => importFiles.value.reduce((s, f) => s + f.size, 0))
const importLimitExceeded = computed(() =>
  importFiles.value.length > 50 || importTotalBytes.value > 20 * 1024 * 1024
)

function setImportFiles(list: File[]) {
  importFiles.value = list.slice(0, 50)
}

function removeImportFile(index: number) {
  importFiles.value = importFiles.value.filter((_, i) => i !== index)
}

async function submitImport() {
  if (!canImportDocuments.value || importing.value) return
  if (!importFiles.value.length) {
    toast.add({ title: 'Selecione ao menos um XML ou ZIP', color: 'warning' })
    return
  }
  if (importLimitExceeded.value) {
    toast.add({
      title: importFiles.value.length > 50
        ? 'Máximo de 50 arquivos por lote'
        : 'Total compactado excede 20 MiB',
      color: 'warning'
    })
    return
  }
  importing.value = true
  try {
    const clientId = isActiveFilterValue(importClientId.value)
      ? Number(importClientId.value)
      : (isActiveFilterValue(filters.client_id) ? Number(filters.client_id) : null)
    const res = await api.documents.importBatch(importFiles.value, { clientId })
    const r = res.data as Record<string, unknown>
    const status = String(r.status || '')
    const batchId = String(r.public_id || r.id || '')
    const imported = Number(r.imported_count ?? 0)
    const failed = Number(r.failed_count ?? 0) + Number(r.unmatched_count ?? 0)
    toast.add({
      title: status
        ? `Lote ${batchId.slice(0, 8)}… · ${status}`
        : `Importação: ${imported} ok, ${failed} falhas`,
      description: 'Progresso continua após fechar este modal. Acompanhe em Importações.',
      color: failed && !imported ? 'error' : 'success',
      actions: batchId
        ? [{
            label: 'Abrir lote',
            onClick: async () => {
              await navigateTo(`/docs/imports/${encodeURIComponent(batchId)}`)
            }
          }]
        : undefined
    })
    importOpen.value = false
    importFiles.value = []
    if (batchId && !r.is_terminal) {
      await navigateTo(`/docs/imports/${encodeURIComponent(batchId)}`)
    } else {
      await reloadActive()
    }
  } catch (caught) {
    try {
      const clientId = isActiveFilterValue(importClientId.value)
        ? Number(importClientId.value)
        : (isActiveFilterValue(filters.client_id) ? Number(filters.client_id) : null)
      const res = await api.documents.import(importFiles.value, clientId)
      const r = res.data
      toast.add({
        title: `Importação: ${r.imported} ok, ${r.skipped} duplicados, ${r.errors} erros`,
        color: r.errors && !r.imported ? 'error' : 'success'
      })
      importOpen.value = false
      importFiles.value = []
      await reloadActive()
    } catch {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao importar XML.'), color: 'error' })
    }
  } finally {
    importing.value = false
  }
}

async function exportCurrentFilter() {
  if (!canCreateExport.value || exporting.value) return
  if (!kindExportAvailable.value) {
    toast.add({
      title: 'Exportação indisponível para este tipo',
      description: `A exportação de ${documentKindLabel(filters.kind)} ainda não está disponível. Use NFS-e ou Todos.`,
      color: 'warning'
    })
    return
  }
  if (!hasExportableCatalogFilters(filters)) {
    toast.add({
      title: 'Defina ao menos um filtro exportável',
      description: 'Use cliente, competência, período, papel, situação ou CNPJ — ou selecione linhas. A busca livre sozinha não gera ZIP.',
      color: 'warning'
    })
    return
  }
  exporting.value = true
  try {
    const job = (await api.exports.create({
      filters: buildExportFiltersFromCatalog(),
      include_events: false
    })).data
    toast.add({
      title: 'Exportação solicitada',
      description: `Job #${job.id} em processamento. Acompanhe em Exportações.`,
      color: 'success',
      actions: [{
        label: 'Abrir Exportações',
        onClick: async () => {
          await navigateTo('/exports')
        }
      }]
    })
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível exportar.'), color: 'error' })
  } finally {
    exporting.value = false
  }
}

async function exportSelection() {
  if (!canCreateExport.value || exporting.value) return
  const keys = [...selectedKeys.value]
  if (!keys.length) {
    toast.add({ title: 'Nenhuma nota selecionada', color: 'warning' })
    return
  }
  if (keys.length > MAX_EXPORT_KEYS) {
    toast.add({
      title: `Máximo de ${MAX_EXPORT_KEYS} notas por seleção`,
      description: 'Reduza a seleção ou use Exportar filtro.',
      color: 'warning'
    })
    return
  }
  exporting.value = true
  try {
    const job = (await api.exports.create({
      filters: { access_keys: keys },
      include_events: false
    })).data
    toast.add({
      title: 'Exportação da seleção solicitada',
      description: `${keys.length} nota(s) · job #${job.id}.`,
      color: 'success',
      actions: [{
        label: 'Abrir Exportações',
        onClick: async () => {
          await navigateTo('/exports')
        }
      }]
    })
    selectedKeys.value = []
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível exportar a seleção.'), color: 'error' })
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  const legacyDocumentView = route.query.view === 'document' || route.query.view === 'nfs'
  // Deep-links documentais (ex. kind=CTE) hidratam filtros; params legados/não aceitos saem da URL.
  if (view.value === 'document' && !selectedAccessKey.value) {
    hydrateFiltersFromQuery()
    if (legacyDocumentView || Object.keys(route.query).length) {
      await syncCatalogQuery(true)
    }
  } else if (Object.keys(route.query).length && selectedAccessKey.value) {
    await router.replace(`/docs/${selectedAccessKey.value}`)
  }
  // Deep link de detalhe: o chrome do modal hidrata via GET da nota.
  if (isActiveFilterValue(filters.client_id)) {
    await onClientChange()
  }
  await loadClients()
  await reloadActive()
})
</script>

<template>
  <!--
    Arquétipo customers.vue. Views por cliente | catálogo no sidebar (Documentos).
    Tabelas com :ui do template.
  -->
  <UDashboardPanel id="docs" class="min-w-0">
    <template #header>
      <UDashboardNavbar title="Documentos" data-testid="page-navbar">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #trailing>
          <UBadge
            :label="view === 'client' ? String(byClientTotal) : String(listTotal || notes.length)"
            variant="subtle"
          />
        </template>
        <template #right>
          <UTooltip text="Atualizar">
            <UButton
              icon="i-lucide-refresh-cw"
              color="neutral"
              variant="ghost"
              square
              aria-label="Atualizar catálogo de documentos"
              :loading="loading"
              @click="reloadActive"
            />
          </UTooltip>
          <UButton
            to="/docs/imports"
            icon="i-lucide-history"
            label="Histórico"
            color="neutral"
            variant="ghost"
            size="sm"
            aria-label="Histórico de lotes de importação"
            :ui="{ label: 'hidden sm:inline' }"
          />
          <UButton
            v-if="canImportDocuments && view === 'document'"
            icon="i-lucide-upload"
            label="Importar XML/ZIP"
            color="neutral"
            variant="outline"
            aria-label="Importar XML ou ZIP de saídas"
            :ui="{ label: 'hidden md:inline' }"
            @click="() => { importOpen = true }"
          />
          <UButton
            v-if="canCreateExport && view === 'document'"
            icon="i-lucide-download"
            label="Exportar filtro"
            color="primary"
            :loading="exporting"
            :disabled="exporting || !kindExportAvailable"
            aria-label="Exportar filtro atual"
            :ui="{ label: 'hidden md:inline' }"
            @click="exportCurrentFilter"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <ShellFormModal
        v-model:open="importOpen"
        title="Importar documentos fiscais"
        description="NF-e 55, NFC-e 65 e CT-e 57 · associação tenant-safe · lote assíncrono"
        submit-label="Enviar lote"
        :loading="importing"
        :disabled="!importFiles.length || importLimitExceeded"
        :show-default-footer="false"
        @cancel="() => { importOpen = false }"
        @submit="submitImport"
      >
        <template #body>
          <div class="space-y-4">
            <p id="import-limits-desc" class="text-sm text-muted">
              Envie XML/ZIP de NF-e, NFC-e ou CT-e. Sem cliente, cada item associa pelas partes
              fiscais reconhecidas. Cliente selecionado só restringe (divergência = CLIENT_MISMATCH).
              Limites: até 50 arquivos e 20&nbsp;MiB compactados. Após o envio, o progresso
              sobrevive ao fechar este modal — use Importações.
            </p>
            <UFormField label="Cliente (restrição opcional)">
              <USelect
                v-model="importClientId"
                :items="[
                  { label: 'Associação automática por emitente', value: FILTER_ALL },
                  ...clients.map(c => ({
                    label: c.display_name || c.legal_name || c.name,
                    value: String(c.id)
                  }))
                ]"
                class="w-full"
                aria-label="Restringir lote a um cliente (opcional)"
              />
            </UFormField>
            <UFileUpload
              :model-value="importFiles"
              multiple
              accept=".xml,.zip,application/xml,application/zip"
              label="Arraste XML/ZIP ou clique para selecionar"
              description="Até 50 arquivos · 20 MiB no total"
              icon="i-lucide-file-up"
              class="w-full"
              :ui="{ base: 'min-h-28' }"
              aria-describedby="import-limits-desc"
              @update:model-value="(files: File | File[] | null | undefined) => {
                const list = !files ? [] : Array.isArray(files) ? files : [files]
                setImportFiles(list)
              }"
            />

            <ul
              v-if="importFiles.length"
              class="max-h-32 space-y-1 overflow-y-auto text-xs text-muted"
              aria-live="polite"
              aria-label="Arquivos selecionados"
            >
              <li
                v-for="(file, idx) in importFiles"
                :key="`${file.name}-${idx}`"
                class="flex items-center justify-between gap-2"
              >
                <span class="truncate">{{ file.name }} ({{ Math.round(file.size / 1024) }} KiB)</span>
                <UButton
                  size="xs"
                  color="neutral"
                  variant="ghost"
                  icon="i-lucide-x"
                  square
                  :aria-label="`Remover ${file.name}`"
                  @click="removeImportFile(idx)"
                />
              </li>
            </ul>
            <p class="text-xs" :class="importLimitExceeded ? 'text-error' : 'text-muted'">
              {{ importFiles.length }} arquivo(s) ·
              {{ (importTotalBytes / (1024 * 1024)).toFixed(2) }} MiB
              <span v-if="importLimitExceeded"> — limite excedido</span>
            </p>
            <UButton
              to="/docs/imports"
              color="neutral"
              variant="link"
              size="sm"
              label="Histórico de lotes"
              class="px-0"
            />
          </div>
        </template>
        <template #footer>
          <ShellModalFooter
            submit-label="Enviar lote"
            :loading="importing"
            :disabled="!importFiles.length || importLimitExceeded"
            @cancel="() => { importOpen = false }"
            @submit="submitImport"
          />
        </template>
      </ShellFormModal>
      <!--
        Body: insights (HomeStats-like) → toolbar busca → tabela (customers).
      -->
      <div class="flex w-full flex-col gap-4 sm:gap-5">
        <!-- Insights de triagem só no catálogo de documentos — por cliente é só captura. -->
        <DocsInsightsBar
          v-if="view === 'document'"
          :insights="insights"
          :loading="insightsLoading"
          :active-queue="triageQueue"
          @select="onTriageSelect"
        />

        <!-- Filtros de catálogo só na visão documento; por cliente a toolbar fica na tabela. -->
        <DocsFilters
          v-if="view === 'document'"
          v-model:filters="filters"
          :clients="clients"
          :establishments="establishments"
          :loading-filters="loadingFilters"
          :reset-key="sessionEpoch"
          view="document"
          :selected-count="selectedKeys.length"
          :can-export="canCreateExport && kindExportAvailable"
          :exporting="exporting"
          @apply="applyFilters"
          @reset="resetFilters"
          @client-change="onClientChange"
          @export-selection="exportSelection"
        />

        <DocsByClient
          v-if="view === 'client'"
          v-model:search="filters.q"
          v-model:operational-filter="clientOperationalFilter"
          v-model:page="byClientPage"
          v-model:per-page="byClientPerPage"
          v-model:sorting="byClientSorting"
          :rows="byClientRows"
          :loading="loading"
          :error="loadError"
          :total="byClientTotal"
          :last-page="byClientLastPage"
          @open-client="openClientNotes"
          @apply="onByClientApply"
          @retry="loadByClient"
        />

        <template v-else>
          <DocsCteCatalogContext
            v-if="showCteContext"
            class="shrink-0 rounded-lg border border-default p-4"
            @pending-resolved="onCtePendingResolved"
          />

          <UAlert
            v-if="!kindCaptureAvailable && !loading"
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            :title="`Captura de ${documentKindLabel(filters.kind)} ainda não disponível`"
            class="shrink-0"
          />

          <DocsCatalog
            v-model:selected-keys="selectedKeys"
            :notes="notes"
            :loading="loading"
            :error="loadError"
            :selected-access-key="selectedAccessKey"
            :page-size="pageSize"
            :total="listTotal"
            :has-more="!!nextCursor"
            :selectable="canCreateExport && kindExportAvailable"
            @select="selectNote"
            @load-more="load(false)"
            @update:page-size="onPageSizeChange"
            @retry="load(true)"
          />
        </template>
      </div>
    </template>
  </UDashboardPanel>

  <DocsDetailModal
    v-model:open="isDetailOpen"
    :access-key="selectedAccessKey"
    :preview="selectedPreview"
  />
</template>
