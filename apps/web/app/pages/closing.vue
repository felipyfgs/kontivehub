<script setup lang="ts">
/**
 * Visão de fechamento mensal de XMLs de saída (competência / prazo operacional).
 * Arquétipo: lista + filtros + stats (customers/home + ShellPagePanel).
 * Não oferece retry remoto, aumento de frequência nem postergação de due_at.
 */
import type { TableColumn } from '@nuxt/ui'
import { TABLE_CELL_BADGE_UI } from '~/utils/table-ui'
import ShellDataTable from '~/components/shell/DataTable.vue'
import type {
  OutboundCapacityForecast,
  OutboundCompetenceSummary,
  OutboundDeadlineMetrics,
  OutboundDeadlinePendingItem,
  OutboundUrgencyBand
} from '~/types/api'
import type { DataTableFilterDefinition, DataTableFilterModel } from '~/types/data-table-filter'
import { createFilterModel, findDefinition } from '~/utils/data-table-filters'
import type { SavedListFilterPayload } from '~/types/saved-list-filters'
import {
  closingFiltersToPayload,
  closingPayloadToFilters,
  hasActiveClosingFiltersForSave
} from '~/utils/saved-list-filters'
import ShellListFilterToolbar from '~/components/shell/ListFilterToolbar.vue'

const api = useApi()
const router = useRouter()
const toast = useToast()
const { canCreateExport, canAccessAdministration, canImportDocuments, me, sessionEpoch } = useDashboard()

const FILTER_ALL = 'all'
const pendingTotal = ref(0)
const pendingLastPage = ref(1)

function defaultCompetence(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

type ClosingNavigationState = {
  competence: string
  band: string
  model: string
  root: string
  source: string
  clientId: string
  page: number
  perPage: number
}

function closingNavigationDefaults(): ClosingNavigationState {
  return {
    competence: defaultCompetence(),
    band: FILTER_ALL,
    model: FILTER_ALL,
    root: '',
    source: FILTER_ALL,
    clientId: '',
    page: 1,
    perPage: 20
  }
}

function normalizeClosingNavigation(value: ClosingNavigationState): ClosingNavigationState {
  const defaults = closingNavigationDefaults()
  const band = String(value.band || FILTER_ALL).toUpperCase()
  const model = String(value.model || FILTER_ALL).toUpperCase()
  const source = String(value.source || FILTER_ALL).toUpperCase()
  const page = Math.max(1, Math.floor(Number(value.page) || 1))
  const perPage = [10, 20, 50].includes(Number(value.perPage)) ? Number(value.perPage) : 20

  return {
    competence: /^\d{4}-\d{2}$/.test(String(value.competence || ''))
      ? String(value.competence)
      : defaults.competence,
    band: ['PLANNED', 'ATTENTION', 'CONTINGENCY', 'OVERDUE'].includes(band)
      ? band
      : FILTER_ALL,
    model: ['55', '65', 'NFE', 'NFCE'].includes(model) ? model : FILTER_ALL,
    root: String(value.root || ''),
    source: ['SVRS', 'AUTXML', 'MANUAL', 'PACKAGE', 'VAULT'].includes(source)
      ? source
      : FILTER_ALL,
    clientId: /^\d+$/.test(String(value.clientId || '')) ? String(value.clientId) : '',
    page,
    perPage
  }
}

const navigationContext = computed(() => [
  me.value?.id ?? 'guest',
  me.value?.current_tenant?.id ?? 'none',
  sessionEpoch.value
].join(':'))
const {
  state: closingNavigation,
  patch: patchClosingNavigation,
  reset: resetClosingNavigation
} = useSurfaceNavigationState(SURFACE_NAVIGATION.closing, closingNavigationDefaults, {
  normalize: normalizeClosingNavigation,
  resetKey: navigationContext
})
const legacyClosingIntent = consumeSurfaceNavigationIntent<Partial<ClosingNavigationState>>(SURFACE_NAVIGATION.closing)
if (legacyClosingIntent) patchClosingNavigation(legacyClosingIntent)

const competence = computed({
  get: () => closingNavigation.value.competence,
  set: value => patchClosingNavigation({ competence: value })
})
const bandFilter = computed({
  get: () => closingNavigation.value.band,
  set: value => patchClosingNavigation({ band: value })
})
const modelFilter = computed({
  get: () => closingNavigation.value.model,
  set: value => patchClosingNavigation({ model: value })
})
const rootFilter = computed({
  get: () => closingNavigation.value.root,
  set: value => patchClosingNavigation({ root: value })
})
const sourceFilter = computed({
  get: () => closingNavigation.value.source,
  set: value => patchClosingNavigation({ source: value })
})
const clientFilter = computed({
  get: () => closingNavigation.value.clientId,
  set: value => patchClosingNavigation({ clientId: value })
})
const pendingPage = computed({
  get: () => closingNavigation.value.page,
  set: value => patchClosingNavigation({ page: value })
})
const pendingPerPage = computed({
  get: () => closingNavigation.value.perPage,
  set: value => patchClosingNavigation({ perPage: value })
})

const summary = ref<OutboundCompetenceSummary | null>(null)
const capacity = ref<OutboundCapacityForecast | null>(null)
const metrics = ref<OutboundDeadlineMetrics | null>(null)
const items = ref<OutboundDeadlinePendingItem[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const actionLoading = ref(false)
const partialNotes = ref('')
const advanceTargetLocal = ref('')
const advanceOpen = ref(false)

const closingFilterDefinitions: DataTableFilterDefinition[] = [
  {
    key: 'band',
    kind: 'option',
    label: 'Faixa',
    emptyValue: FILTER_ALL,
    items: [
      { label: 'Planejado', value: 'PLANNED' },
      { label: 'Atenção', value: 'ATTENTION' },
      { label: 'Contingência', value: 'CONTINGENCY' },
      { label: 'Vencido', value: 'OVERDUE' }
    ]
  },
  {
    key: 'model',
    kind: 'option',
    label: 'Modelo',
    emptyValue: FILTER_ALL,
    items: [
      { label: 'NF-e (55)', value: '55' },
      { label: 'NFC-e (65)', value: '65' }
    ]
  },
  {
    key: 'source',
    kind: 'option',
    label: 'Fonte',
    emptyValue: FILTER_ALL,
    items: [
      { label: 'SVRS', value: 'SVRS' },
      { label: 'autXML', value: 'AUTXML' },
      { label: 'Upload / ZIP', value: 'MANUAL' },
      { label: 'Pacote oficial', value: 'PACKAGE' },
      { label: 'Vault', value: 'VAULT' }
    ]
  },
  {
    key: 'clientId',
    kind: 'client',
    label: 'Cliente',
    multiple: false
  },
  {
    key: 'root',
    kind: 'text',
    label: 'Raiz CNPJ',
    emptyValue: '',
    operator: 'eq'
  }
]

function modelsFromClosingState(): DataTableFilterModel[] {
  const models: DataTableFilterModel[] = []
  for (const key of ['band', 'model', 'source', 'root'] as const) {
    const def = findDefinition(closingFilterDefinitions, key)
    if (!def) continue
    const raw = key === 'band'
      ? bandFilter.value
      : key === 'model'
        ? modelFilter.value
        : key === 'source'
          ? sourceFilter.value
          : rootFilter.value
    const model = createFilterModel(def, raw)
    if (model) models.push(model)
  }
  const clientDef = findDefinition(closingFilterDefinitions, 'clientId')
  if (clientDef) {
    const id = Number(clientFilter.value)
    if (Number.isFinite(id) && id >= 1) {
      const model = createFilterModel(clientDef, id)
      if (model) models.push(model)
    }
  }
  return models
}

const chipModels = ref<DataTableFilterModel[]>(modelsFromClosingState())

function syncClosingChips() {
  chipModels.value = modelsFromClosingState()
}

function onStructuredFilters(models: DataTableFilterModel[]) {
  const band = models.find(m => m.key === 'band')
  const model = models.find(m => m.key === 'model')
  const source = models.find(m => m.key === 'source')
  const root = models.find(m => m.key === 'root')
  const client = models.find(m => m.key === 'clientId')
  bandFilter.value = band ? String(band.value) : FILTER_ALL
  modelFilter.value = model ? String(model.value) : FILTER_ALL
  sourceFilter.value = source ? String(source.value) : FILTER_ALL
  rootFilter.value = root ? String(root.value) : ''
  clientFilter.value = client && typeof client.value === 'number'
    ? String(client.value)
    : client
      ? String(client.value)
      : ''
  chipModels.value = models
  // watch nos filtros dispara load
}

function onClearStructuredFilters() {
  bandFilter.value = FILTER_ALL
  modelFilter.value = FILTER_ALL
  sourceFilter.value = FILTER_ALL
  rootFilter.value = ''
  clientFilter.value = ''
  chipModels.value = []
}

function closingGetPayload(): SavedListFilterPayload {
  return closingFiltersToPayload({
    competence: competence.value,
    band: bandFilter.value,
    model: modelFilter.value,
    root: rootFilter.value,
    source: sourceFilter.value,
    client_id: clientFilter.value
  })
}

function closingCanSave(): boolean {
  return hasActiveClosingFiltersForSave({
    competence: competence.value,
    band: bandFilter.value,
    model: modelFilter.value,
    root: rootFilter.value,
    source: sourceFilter.value,
    client_id: clientFilter.value
  })
}

function onApplyClosingPreset(payload: SavedListFilterPayload) {
  const next = closingPayloadToFilters(payload, competence.value)
  competence.value = next.competence || competence.value
  bandFilter.value = next.band
  modelFilter.value = next.model
  rootFilter.value = next.root
  sourceFilter.value = next.source
  clientFilter.value = next.client_id
  pendingPage.value = 1
  syncClosingChips()
  // watch nos filtros dispara load
}

const columns: TableColumn<OutboundDeadlinePendingItem>[] = [
  { accessorKey: 'urgency_band', header: 'Faixa' },
  { accessorKey: 'access_key_masked', header: 'Chave' },
  {
    accessorKey: 'model',
    header: 'Modelo',
    meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
  },
  {
    accessorKey: 'due_at',
    header: 'Prazo (due)',
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  {
    accessorKey: 'target_at',
    header: 'Meta',
    meta: { class: { th: 'hidden lg:table-cell', td: 'hidden lg:table-cell' } }
  },
  {
    accessorKey: 'recovery_status',
    header: 'Técnico',
    meta: { class: { th: 'hidden md:table-cell', td: 'hidden md:table-cell' } }
  },
  { id: 'next', header: 'Próximo passo' }
]

const projection = computed(() => capacity.value?.projection ?? null)

const filteredItems = computed(() => {
  let list = items.value
  if (modelFilter.value !== FILTER_ALL) {
    const m = modelFilter.value
    list = list.filter((row) => {
      const model = String(row.model || '')
      if (m === '55' || m === 'NFE') return model === '55' || model === 'NFE' || model.includes('55')
      if (m === '65' || m === 'NFCE') return model === '65' || model === 'NFCE' || model.includes('65')
      return true
    })
  }
  if (rootFilter.value) {
    const r = rootFilter.value.replace(/\D/g, '').slice(0, 8)
    if (r) {
      list = list.filter(row => String(row.root_cnpj || '').includes(r)
        || String(row.access_key_masked || '').includes(r))
    }
  }
  if (sourceFilter.value !== FILTER_ALL) {
    const s = sourceFilter.value.toUpperCase()
    list = list.filter((row) => {
      const src = String(row.capture_source || '').toUpperCase()
      if (!src) return true
      return src.includes(s)
    })
  }
  return list
})

const primaryAction = computed(() => {
  const bands = summary.value?.by_band ?? {}
  const overdue = bands.OVERDUE ?? 0
  const contingency = bands.CONTINGENCY ?? 0
  const attention = bands.ATTENTION ?? 0
  if (overdue > 0 || contingency > 0) {
    return {
      kind: 'import' as const,
      label: 'Importação assistida (XML/ZIP/pacote)',
      description: 'Contingência/vencidos: a ação principal é importar — a SVRS segue só nos slots seguros.'
    }
  }
  if (attention > 0) {
    return {
      kind: 'batch' as const,
      label: 'Preparar lote assistido',
      description: 'Atenção: prepare importação/pacote sem disparar retry remoto.'
    }
  }
  return {
    kind: 'wait' as const,
    label: 'Aguardar fontes preferenciais',
    description: 'Planejado: priorize autXML/vault; a SVRS só entra após acomodação e no slot calculado.'
  }
})

function bandColor(band?: OutboundUrgencyBand | null): 'success' | 'info' | 'warning' | 'error' | 'neutral' {
  switch ((band || '').toUpperCase()) {
    case 'CAPTURED': return 'success'
    case 'PLANNED': return 'info'
    case 'ATTENTION': return 'warning'
    case 'CONTINGENCY': return 'warning'
    case 'OVERDUE': return 'error'
    default: return 'neutral'
  }
}

function bandIcon(band?: OutboundUrgencyBand | null): string {
  switch ((band || '').toUpperCase()) {
    case 'CAPTURED': return 'i-lucide-check-circle-2'
    case 'PLANNED': return 'i-lucide-calendar'
    case 'ATTENTION': return 'i-lucide-triangle-alert'
    case 'CONTINGENCY': return 'i-lucide-life-buoy'
    case 'OVERDUE': return 'i-lucide-alarm-clock-off'
    default: return 'i-lucide-circle'
  }
}

function bandLabel(band?: OutboundUrgencyBand | null): string {
  switch ((band || '').toUpperCase()) {
    case 'CAPTURED': return 'Capturado'
    case 'PLANNED': return 'Planejado'
    case 'ATTENTION': return 'Atenção'
    case 'CONTINGENCY': return 'Contingência'
    case 'OVERDUE': return 'Vencido'
    default: return band || '—'
  }
}

function nextStepLabel(step?: string | null): string {
  switch (step) {
    case 'ASSISTED_IMPORT': return 'Importação assistida'
    case 'PREPARE_ASSISTED_BATCH': return 'Preparar lote'
    case 'WAIT_OR_PREFER_AUTXML': return 'Aguardar / autXML'
    default: return step || '—'
  }
}

function technicalLabel(row: OutboundDeadlinePendingItem): string {
  if (row.failure_label) return row.failure_label
  if (row.failure_reason) return String(row.failure_reason)
  if (row.recovery_status) return String(row.recovery_status)
  return 'OK / aguardando'
}

async function load() {
  const epoch = sessionEpoch.value
  loading.value = true
  try {
    const comp = competence.value
    const band = bandFilter.value === FILTER_ALL ? undefined : bandFilter.value
    const model = modelFilter.value === FILTER_ALL ? undefined : modelFilter.value
    const root = rootFilter.value || undefined
    const source = sourceFilter.value === FILTER_ALL ? undefined : sourceFilter.value
    const clientId = clientFilter.value ? Number(clientFilter.value) : undefined
    const [sumRes, capRes, pendRes, metRes] = await Promise.allSettled([
      api.outbound.deadline.competence(comp),
      api.outbound.deadline.capacity(comp),
      api.outbound.deadline.pending({
        competence: comp,
        urgency_band: band,
        model,
        root_cnpj: root,
        source,
        client_id: clientId && clientId > 0 ? clientId : undefined,
        page: pendingPage.value,
        per_page: pendingPerPage.value
      }),
      api.outbound.deadline.metrics(comp)
    ])

    if (epoch !== sessionEpoch.value) return

    if (sumRes.status === 'fulfilled') {
      summary.value = sumRes.value.data
    }
    if (capRes.status === 'fulfilled') {
      capacity.value = capRes.value.data
      if (capacity.value.projection?.target_at) {
        advanceTargetLocal.value = capacity.value.projection.target_at.slice(0, 16)
      }
    }
    if (pendRes.status === 'fulfilled') {
      items.value = pendRes.value.data
      pendingTotal.value = pendRes.value.meta.total
      pendingLastPage.value = pendRes.value.meta.last_page
    }
    if (metRes.status === 'fulfilled') {
      metrics.value = metRes.value.data
    }

    const failed = [sumRes, capRes, pendRes].filter(r => r.status === 'rejected')
    if (failed.length === 3) {
      loadError.value = apiErrorMessage(
        (failed[0] as PromiseRejectedResult).reason,
        'Não foi possível carregar o fechamento.'
      )
    } else {
      loadError.value = null
    }
  } catch (caught) {
    loadError.value = apiErrorMessage(caught, 'Erro ao carregar fechamento.')
    toast.add({ title: loadError.value, color: 'error' })
  } finally {
    loading.value = false
  }
}

async function confirmPartial() {
  if (!canCreateExport.value) {
    toast.add({ title: 'Sem permissão para confirmar parcialmente.', color: 'warning' })
    return
  }
  actionLoading.value = true
  try {
    await api.outbound.deadline.confirmPartial({
      competence: competence.value,
      notes: partialNotes.value || undefined
    })
    toast.add({ title: 'Exportação parcial confirmada (documentos conhecidos).', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao confirmar parcial.'), color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

async function exportMonthly() {
  if (!canCreateExport.value) {
    toast.add({ title: 'Sem permissão para exportar.', color: 'warning' })
    return
  }
  actionLoading.value = true
  try {
    const res = await api.outbound.deadline.exportMonthly({
      competence: competence.value,
      notes: partialNotes.value || undefined
    })
    toast.add({
      title: res.data.has_manifest
        ? 'Exportação enfileirada com manifesto de ausências.'
        : 'Exportação mensal enfileirada.',
      color: 'success'
    })
    await router.push('/exports')
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao exportar competência.'), color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

async function advanceTarget() {
  if (!canAccessAdministration.value) {
    toast.add({ title: 'A antecipação exige permissão e autenticação recente.', color: 'warning' })
    return
  }
  if (!advanceTargetLocal.value) {
    toast.add({ title: 'Informe a nova meta (target_at).', color: 'warning' })
    return
  }
  actionLoading.value = true
  try {
    await api.outbound.deadline.advanceTarget({
      competence: competence.value,
      target_at: new Date(advanceTargetLocal.value).toISOString()
    })
    toast.add({ title: 'Meta interna antecipada (due_at e budgets inalterados).', color: 'success' })
    advanceOpen.value = false
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível antecipar a meta.'), color: 'error' })
  } finally {
    actionLoading.value = false
  }
}

function setPendingPerPage(next: number) {
  const allowed = [10, 20, 50]
  const target = allowed.includes(Number(next)) ? Number(next) : 20
  if (pendingPerPage.value === target) return
  pendingPerPage.value = target
  if (pendingPage.value !== 1) {
    pendingPage.value = 1
    return
  }
  void load()
}

watch(
  [competence, bandFilter, modelFilter, rootFilter, sourceFilter, clientFilter],
  () => {
    if (pendingPage.value !== 1) {
      pendingPage.value = 1
      return
    }
    void load()
  },
  { immediate: true }
)

watch(pendingPage, () => void load())

watch(sessionEpoch, () => {
  summary.value = null
  capacity.value = null
  metrics.value = null
  items.value = []
  pendingTotal.value = 0
  loadError.value = null
  resetClosingNavigation()
  chipModels.value = []
  // ShellListFilterToolbar limpa presets via reset-key=sessionEpoch
  void load()
})
</script>

<template>
  <!--
    Arquétipo lista admin (customers.vue) via ShellPagePanel.
    Fontes: .local/reference/.../customers.vue + clients/index.vue + table-ui presets.
  -->
  <ShellPagePanel id="closing">
    <template #header>
      <ShellPageNavbar title="Fechamento de saídas">
        <template #right>
          <ShellNavbarRefresh
            :loading="loading"
            aria-label="Atualizar fechamento"
            @click="load"
          />
        </template>
      </ShellPageNavbar>
    </template>

    <template #body>
      <!--
        Competência no slot de ações (contexto da tela).
        Chips/presets: ShellListFilterToolbar (surface closing.list).
      -->
      <div
        class="mb-4 w-full min-w-0"
        data-testid="closing-filter-toolbar"
      >
        <ShellListFilterToolbar
          :show-search="false"
          :definitions="closingFilterDefinitions"
          :models="chipModels"
          :loading="loading"
          :reset-key="sessionEpoch"
          surface="closing.list"
          :get-payload="closingGetPayload"
          :can-save="closingCanSave"
          test-id-prefix="closing"
          @update:models="onStructuredFilters"
          @clear="onClearStructuredFilters"
          @refresh="load"
          @apply-preset="onApplyClosingPreset"
        >
          <template #actions>
            <UFormField
              label="Competência"
              class="w-full shrink-0 sm:w-40"
            >
              <UInput
                v-model="competence"
                type="month"
                data-testid="closing-competence"
                aria-label="Competência (AAAA-MM)"
              />
            </UFormField>
          </template>
        </ShellListFilterToolbar>
      </div>

      <ShellLoadError
        v-if="loadError"
        :title="loadError"
        test-id="closing-load-error"
        @retry="load"
      />

      <UAlert
        v-if="projection?.at_risk"
        class="mb-3"
        icon="i-lucide-gauge"
        color="error"
        variant="subtle"
        title="Capacidade insuficiente até a meta"
      />

      <UAlert
        v-for="alert in (metrics?.alerts || [])"
        :key="alert.code"
        class="mb-2"
        :color="alert.severity === 'critical' ? 'error' : alert.severity === 'high' ? 'warning' : 'info'"
        variant="subtle"
        :title="alert.message"
        icon="i-lucide-bell"
      />

      <!-- Ações compactas no body (sem card de instrução) -->
      <div
        class="mb-4 flex min-w-0 flex-wrap items-center gap-2"
        data-testid="closing-actions"
      >
        <UButton
          v-if="canCreateExport"
          size="sm"
          color="neutral"
          variant="soft"
          icon="i-lucide-file-check"
          label="Parcial"
          :loading="actionLoading"
          :disabled="(summary?.pending_total ?? 0) === 0"
          @click="confirmPartial"
        />
        <UButton
          v-if="canCreateExport"
          size="sm"
          color="primary"
          icon="i-lucide-package"
          label="Exportar"
          :loading="actionLoading"
          @click="exportMonthly"
        />
        <UButton
          v-if="canAccessAdministration"
          size="sm"
          color="neutral"
          variant="outline"
          icon="i-lucide-calendar-minus"
          label="Antecipar meta"
          @click="() => { advanceOpen = true }"
        />
        <UButton
          v-if="canImportDocuments && primaryAction.kind !== 'wait'"
          size="sm"
          color="neutral"
          variant="ghost"
          to="/docs/imports"
          icon="i-lucide-upload"
          :label="primaryAction.kind === 'import' ? 'Importar' : 'Importação'"
        />
        <UInput
          v-if="canCreateExport"
          v-model="partialNotes"
          size="sm"
          class="w-full min-w-0 sm:max-w-xs"
          placeholder="Nota (opcional)"
          aria-label="Notas de confirmação parcial"
        />
      </div>

      <ShellFormModal
        v-model:open="advanceOpen"
        title="Antecipar meta"
        submit-label="Aplicar antecipação"
        :loading="actionLoading"
        @submit="advanceTarget"
      >
        <template #body>
          <UFormField label="Nova meta (local)">
            <UInput
              v-model="advanceTargetLocal"
              type="datetime-local"
              aria-label="Nova meta interna"
              class="w-full"
            />
          </UFormField>
        </template>
      </ShellFormModal>

      <p class="text-xs text-muted">
        Escopo: {{ summary?.completeness_scope || 'known_documents_only' }}.
        Papel atual: {{ me?.tenant_role || '—' }}.
        Atalho: <kbd class="px-1 rounded border">g</kbd> então <kbd class="px-1 rounded border">f</kbd>.
      </p>

      <ShellDataTable
        test-id="closing-table"
        ui-preset="monitoring-compact"
        primary-column-id="access_key_masked"
        status-column-id="urgency_band"
        :summary-column-ids="['model', 'due_at', 'recovery_status', 'next']"
        :columns="columns"
        :data="filteredItems"
        :loading="loading"
        :page="pendingPage"
        :total="pendingTotal"
        :items-per-page="pendingPerPage"
        per-page-aria-label="Pendências por página"
        @update:page="pendingPage = $event"
        @update:items-per-page="setPendingPerPage"
      >
        <template #urgency_band-cell="{ row }">
          <div class="flex w-full min-w-0 items-center gap-2">
            <UIcon
              :name="bandIcon(row.original.urgency_band)"
              class="size-4 shrink-0"
              :class="{
                'text-error': bandColor(row.original.urgency_band) === 'error',
                'text-warning': bandColor(row.original.urgency_band) === 'warning',
                'text-info': bandColor(row.original.urgency_band) === 'info',
                'text-success': bandColor(row.original.urgency_band) === 'success'
              }"
            />
            <UBadge
              :color="bandColor(row.original.urgency_band)"
              variant="subtle"
              size="md"
              class="h-8 min-w-0 flex-1 justify-center tabular-nums font-normal"
              :ui="TABLE_CELL_BADGE_UI"
            >
              {{ bandLabel(row.original.urgency_band) }}
            </UBadge>
            <UBadge
              v-if="row.original.capacity_at_risk"
              color="error"
              variant="outline"
              size="sm"
              class="shrink-0"
            >
              Capacidade em risco
            </UBadge>
          </div>
        </template>
        <template #access_key_masked-cell="{ row }">
          <span class="font-mono text-xs">{{ row.original.access_key_masked || '—' }}</span>
        </template>
        <template #due_at-cell="{ row }">
          {{ formatDateTime(row.original.due_at) }}
        </template>
        <template #target_at-cell="{ row }">
          {{ formatDateTime(row.original.target_at) }}
        </template>
        <template #recovery_status-cell="{ row }">
          <span class="text-xs text-muted" :title="technicalLabel(row.original)">
            {{ technicalLabel(row.original) }}
          </span>
        </template>
        <template #next-cell="{ row }">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm">{{ nextStepLabel(row.original.next_step) }}</span>
            <UButton
              v-if="canImportDocuments && ['CONTINGENCY', 'OVERDUE', 'ATTENTION'].includes(String(row.original.urgency_band || '').toUpperCase())"
              size="xs"
              color="primary"
              variant="soft"
              icon="i-lucide-upload"
              label="Importar"
              to="/docs/imports"
            />
          </div>
        </template>
        <template #empty>
          <div class="py-8 text-center text-sm text-muted">
            Nenhuma pendência para os filtros.
          </div>
        </template>
        <template #footer>
          <span class="tabular-nums">{{ pendingTotal }}</span> pendência(s)
          <template v-if="pendingLastPage > 1">
            <span class="text-dimmed"> · </span>
            página {{ pendingPage }} de {{ pendingLastPage }}
          </template>
        </template>
      </ShellDataTable>
    </template>
  </ShellPagePanel>
</template>
