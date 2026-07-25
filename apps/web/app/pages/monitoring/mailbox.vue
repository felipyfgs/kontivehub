<script setup lang="ts">
/**
 * Caixa Postal — mestre–detalhe (arquétipo Inbox).
 * Página de Monitoramento em largura total, sem navegação global duplicada;
 * lista + NuxtPage (detalhe em /monitoring/mailbox/[id]) abaixo das tabs.
 * Desktop: painéis adjacentes; mobile: detalhe em USlideover.
 *
 * Filtros: triage (status) + client via ModuleToolbar + surface monitoring.mailbox
 * (presets salvos) no painel da lista. API mailbox não aplica `q` — busca desligada.
 */
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import type { MailboxListItem } from '~/components/monitoring/MailboxList.vue'
import type { MonitoringFilterConfig, MonitoringFilterValue } from '~/types/fiscal-modules'
import { consumeMailboxClientFilterHandoff } from '~/utils/mailbox-handoff'
import { MAILBOX_TRIAGE_FILTER_ITEMS } from '~/utils/mailbox-triage'
import {
  normalizeMonitoringFilters,
  resetMonitoringFilters
} from '~/utils/monitoring-filters'
import ShellTableFooter from '~/components/shell/TableFooter.vue'

const api = useApi()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const { sessionEpoch } = useDashboard()
const {
  page, perPage, total, lastPage, clientId, q,
  loading, loadError, applyPaginator, syncUrl, resetPage
} = useServerPage()

type MailboxTriageFilter = (typeof MAILBOX_TRIAGE_FILTER_ITEMS)[number]['value']
const triage = ref<MailboxTriageFilter>('all')
const rows = ref<MailboxListItem[]>([])
const listRef = ref<{ focusMessage: (id: number | null | undefined) => void } | null>(null)
/** ID a restaurar foco ao fechar detalhe (desktop/mobile). */
const lastFocusedId = ref<number | null>(null)
const alerts = ref<Array<Record<string, unknown>>>([])
const alertsError = ref<string | null>(null)
const alertsModalOpen = ref(false)
const syncModalOpen = ref(false)
const {
  status: monitoringStatus,
  preview: syncPreview,
  loading: monitoringLoading,
  previewing: syncPreviewing,
  confirming: syncConfirming,
  saving: monitoringSaving,
  error: monitoringError,
  load: loadMonitoring,
  save: saveMonitoring,
  previewNow,
  confirmNow
} = useMailboxMonitoring()
let loadSeq = 0
let filterTransactionDepth = 0
let handoffApplied = false

const triageItems = MAILBOX_TRIAGE_FILTER_ITEMS.map(item => ({
  label: item.label,
  value: item.value
}))

const filterConfig: MonitoringFilterConfig = {
  // List API de mailbox não aceita `q` — busca seria decorativa.
  search: false,
  fields: [
    {
      key: 'status',
      kind: 'option',
      label: 'Triagem',
      items: triageItems
    },
    { key: 'clientId', kind: 'client', label: 'Cliente', multiple: false }
  ]
}

const filters = computed<MonitoringFilterValue>(() => normalizeMonitoringFilters({
  // status reutilizado como eixo de triagem na surface mailbox
  status: triage.value,
  clientIds: (() => {
    const n = Number(clientId.value)
    return Number.isFinite(n) && n >= 1 ? [Math.floor(n)] : []
  })(),
  q: ''
}))

const selectedId = computed(() => {
  const id = Number(route.params.id)
  return Number.isFinite(id) && id > 0 ? id : null
})

const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('lg')
/** Desktop: detalhe sob demanda. */
const detailOpen = ref(false)
const monitoringChromeOpen = ref(false)

const detailPaneVisible = computed(
  () => !isMobile.value && Boolean(selectedId.value) && detailOpen.value
)

const mobileOpen = computed({
  get: () => Boolean(isMobile.value && selectedId.value),
  set: (open: boolean) => {
    if (!open && selectedId.value) {
      closeDetail()
    }
  }
})

watch(
  selectedId,
  (id, prev) => {
    if (!id) {
      detailOpen.value = false
      return
    }
    if (id && !prev && !isMobile.value) detailOpen.value = true
  },
  { immediate: true }
)

async function loadAlerts() {
  alertsError.value = null
  try {
    const res = await api.fiscal.mailbox.alerts()
    const all = ((res.data as Array<Record<string, unknown>>) || [])
    const clientNum = Number(clientId.value)
    const scoped = Number.isFinite(clientNum) && clientNum > 0
      ? all.filter(a => Number(a.client_id) === clientNum)
      : all
    alerts.value = scoped.slice(0, 8)
  } catch (caught) {
    alerts.value = []
    alertsError.value = apiErrorMessage(caught, 'Falha ao carregar alertas.')
  }
}

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    if (!handoffApplied) {
      handoffApplied = true
      const handoffClientId = consumeMailboxClientFilterHandoff()
      if (handoffClientId != null) {
        clientId.value = String(handoffClientId)
      }
    }
    await syncUrl()
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    const clientNum = Number(clientId.value)
    const res = await api.fiscal.mailbox.list({
      page: page.value,
      per_page: perPage.value,
      client_id: Number.isFinite(clientNum) && clientNum > 0 ? clientNum : undefined,
      triage_status: triage.value !== 'all' ? triage.value : undefined
    }) as Record<string, unknown>
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    rows.value = ((res.data as MailboxListItem[]) || [])
    applyPaginator(res)
    if (res.total == null && !(res.meta as { total?: number } | undefined)?.total) {
      total.value = rows.value.length
      lastPage.value = 1
    }
    void loadAlerts()
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    rows.value = []
    total.value = 0
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar caixas postais.')
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function applyModuleFilters(nextValue: MonitoringFilterValue) {
  const next = normalizeMonitoringFilters(nextValue)
  const nextClient = next.clientIds[0] != null ? String(next.clientIds[0]) : ''
  const nextTriage = (next.status || 'all') as MailboxTriageFilter
  if (triage.value === nextTriage && clientId.value === nextClient) return

  filterTransactionDepth += 1
  try {
    triage.value = nextTriage
    clientId.value = nextClient
    // API mailbox não aplica q — limpar residual de useServerPage.
    q.value = ''
    resetPage()
    await nextTick()
  } finally {
    filterTransactionDepth -= 1
  }
  await load()
}

function resetModuleFilters() {
  void applyModuleFilters(resetMonitoringFilters())
}

function selectMessage(id: number) {
  lastFocusedId.value = id
  if (!isMobile.value) detailOpen.value = true
  void router.push(`/monitoring/mailbox/${id}`)
}

function closeDetail() {
  detailOpen.value = false
  const restoreId = selectedId.value || lastFocusedId.value
  void router.push('/monitoring/mailbox').then(() => {
    nextTick(() => {
      listRef.value?.focusMessage(restoreId)
    })
  })
}

function toggleDetail() {
  if (!selectedId.value || isMobile.value) return
  detailOpen.value = !detailOpen.value
}

function onTriaged() {
  void load()
}

function setPerPage(next: number) {
  const allowed = [10, 20, 50]
  const target = allowed.includes(Number(next)) ? Number(next) : 20
  if (perPage.value === target) return
  perPage.value = target
  resetPage()
  void load()
}

watch(page, () => {
  if (filterTransactionDepth > 0) return
  void load()
})
watch([clientId, triage], () => {
  if (filterTransactionDepth > 0) return
  resetPage()
  void load()
})
watch(sessionEpoch, () => {
  filterTransactionDepth += 1
  try {
    triage.value = 'all'
    clientId.value = ''
    q.value = ''
    rows.value = []
    total.value = 0
    lastPage.value = 1
    page.value = 1
    alerts.value = []
    detailOpen.value = false
    monitoringChromeOpen.value = false
    handoffApplied = false
  } finally {
    filterTransactionDepth -= 1
  }
  void load()
  void loadMonitoring()
})
onMounted(() => {
  void load()
  void loadMonitoring()
})

async function saveMonitoringSettings(value: { enabled: boolean, mode: 'ECONOMICO' | 'DIARIO_COMPLETO' }) {
  if (await saveMonitoring(value)) {
    toast.add({
      title: value.enabled ? 'Busca automática ativada' : 'Busca automática desativada',
      color: 'success'
    })
  }
}

async function openSyncPreview() {
  const result = await previewNow()
  if (result) syncModalOpen.value = true
}

async function confirmSync() {
  const result = await confirmNow()
  if (!result) return
  syncModalOpen.value = false
  toast.add({
    title: 'Atualização iniciada',
    description: 'As novas mensagens aparecerão aqui assim que a busca terminar.',
    color: 'success',
    icon: 'i-lucide-circle-check'
  })
  await load()
}

function openAlert(alert: Record<string, unknown>) {
  alertsModalOpen.value = false
  const messageId = Number(alert.mailbox_message_id)
  if (Number.isFinite(messageId) && messageId > 0) {
    selectMessage(messageId)
    return
  }
  const deep = String(alert.deep_link || '')
  if (deep.startsWith('/monitoring/mailbox')) {
    void router.push(deep)
  }
}

function showAlerts(): void {
  alertsModalOpen.value = true
}

defineShortcuts({
  escape: () => {
    if (isMobile.value) return
    if (detailOpen.value) {
      detailOpen.value = false
      return
    }
    if (selectedId.value) closeDetail()
  }
})

function alertSeverityLabel(alert: Record<string, unknown>): string {
  const severity = String(alert.severity || '').toLowerCase()
  if (severity === 'high') return 'Alta'
  if (severity === 'medium') return 'Média'
  return 'Informação'
}

function alertSeverityColor(alert: Record<string, unknown>): 'error' | 'warning' | 'neutral' {
  const severity = String(alert.severity || '').toLowerCase()
  if (severity === 'high') return 'error'
  if (severity === 'medium') return 'warning'
  return 'neutral'
}

function alertDescription(alert: Record<string, unknown>): string {
  return String(alert.body || '')
    .replace(/\s*·\s*Abrir detalhe autorizado no MonitorHub\.?$/i, '')
}
</script>

<template>
  <div class="flex min-h-0 w-full flex-1 flex-col">
    <!-- Chrome Fiscal em largura total — acima do mestre–detalhe -->
    <UDashboardNavbar
      title="Caixas Postais"
      data-testid="page-navbar"
      class="shrink-0"
    >
      <template #leading>
        <UDashboardSidebarCollapse />
      </template>
      <template #trailing>
        <UBadge
          :label="String(total)"
          variant="subtle"
        />
      </template>
      <template #right>
        <UTooltip
          :text="detailOpen ? 'Fechar detalhe' : 'Abrir detalhe'"
          class="hidden lg:inline-flex"
        >
          <UButton
            icon="i-lucide-panel-right"
            :color="detailOpen ? 'primary' : 'neutral'"
            :variant="detailOpen ? 'soft' : 'ghost'"
            :disabled="!selectedId"
            :aria-label="detailOpen ? 'Fechar detalhe' : 'Abrir detalhe'"
            :aria-pressed="detailOpen"
            data-testid="mailbox-detail-toggle"
            @click="toggleDetail"
          />
        </UTooltip>
        <UTooltip text="Alertas">
          <UButton
            color="neutral"
            variant="ghost"
            square
            aria-label="Abrir alertas da Caixa Postal"
            data-testid="mailbox-alerts-trigger"
            @click="showAlerts"
          >
            <UChip
              color="error"
              :show="alerts.length > 0 || Boolean(alertsError)"
              inset
            >
              <UIcon name="i-lucide-bell" class="size-5 shrink-0" />
            </UChip>
          </UButton>
        </UTooltip>
      </template>
    </UDashboardNavbar>

    <div class="shrink-0 space-y-3 px-4 pt-4 sm:px-6">
      <FiscalModuleAvailabilityBanner module-key="mailbox" />

      <UCollapsible
        v-model:open="monitoringChromeOpen"
        class="flex w-full flex-col gap-2"
        data-testid="mailbox-monitoring-collapsible"
      >
        <UButton
          class="group"
          color="neutral"
          variant="ghost"
          block
          :label="monitoringChromeOpen ? 'Ocultar monitoramento e sync' : 'Monitoramento e sync'"
          :trailing-icon="monitoringChromeOpen ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          :ui="{
            trailingIcon: 'group-data-[state=open]:rotate-180 transition-transform duration-200'
          }"
          data-testid="mailbox-monitoring-chrome-toggle"
        >
          <template #leading>
            <UChip
              color="error"
              :show="Boolean(monitoringError)"
              inset
            >
              <UIcon name="i-lucide-radar" class="size-5 shrink-0" />
            </UChip>
          </template>
        </UButton>
        <template #content>
          <MonitoringMailboxMonitoringCard
            :status="monitoringStatus"
            :message-count="total"
            :loading="monitoringLoading"
            :saving="monitoringSaving"
            :previewing="syncPreviewing"
            :error="monitoringError"
            @refresh="loadMonitoring"
            @save="saveMonitoringSettings"
            @update-now="openSyncPreview"
          />
        </template>
      </UCollapsible>
    </div>

    <USlideover
      v-model:open="alertsModalOpen"
      title="Alertas da Caixa Postal"
    >
      <template #body>
        <UAlert
          v-if="alertsError"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="alertsError"
          class="mb-3"
        />

        <div v-if="!alertsError && alerts.length === 0" class="py-10 text-center">
          <UIcon name="i-lucide-bell-off" class="mx-auto size-10 text-dimmed" />
          <p class="mt-3 text-sm text-muted">
            Nenhum alerta ativo.
          </p>
        </div>

        <button
          v-for="alert in alerts"
          :key="String(alert.id)"
          type="button"
          class="relative -mx-3 flex w-[calc(100%+1.5rem)] items-center gap-3 rounded-md px-3 py-2.5 text-left hover:bg-elevated/50"
          @click="openAlert(alert)"
        >
          <UChip
            color="error"
            :show="String(alert.severity).toLowerCase() === 'high'"
            inset
          >
            <div class="flex size-10 items-center justify-center rounded-full bg-elevated">
              <UIcon name="i-lucide-triangle-alert" class="size-5 text-warning" />
            </div>
          </UChip>

          <span class="min-w-0 flex-1 text-sm">
            <span class="flex items-center justify-between gap-2">
              <span class="truncate font-medium text-highlighted">{{ alert.title }}</span>
              <UBadge
                size="sm"
                variant="subtle"
                :color="alertSeverityColor(alert)"
                :label="alertSeverityLabel(alert)"
              />
            </span>
            <span
              v-if="alertDescription(alert)"
              class="mt-0.5 line-clamp-2 block text-dimmed"
            >{{ alertDescription(alert) }}</span>
          </span>
        </button>
      </template>
    </USlideover>

    <UModal
      v-model:open="syncModalOpen"
      title="Atualizar Caixa Postal agora"
      description="Confirme para buscar novas mensagens dos clientes do escritório."
      :ui="{ footer: 'justify-end' }"
    >
      <template #body>
        <div v-if="syncPreview" data-testid="mailbox-sync-preview">
          <UAlert
            :color="syncPreview.can_confirm ? 'primary' : 'warning'"
            variant="subtle"
            :icon="syncPreview.can_confirm ? 'i-lucide-refresh-cw' : 'i-lucide-circle-x'"
            :title="syncPreview.can_confirm ? 'Tudo pronto para atualizar' : 'Atualização indisponível no momento'"
            :description="syncPreview.can_confirm
              ? 'A busca será executada em segundo plano e respeitará os limites definidos para o escritório.'
              : 'Nenhuma consulta foi realizada. Tente novamente mais tarde ou fale com o suporte.'"
          />
        </div>
      </template>
      <template #footer="{ close }">
        <UButton
          color="neutral"
          variant="outline"
          label="Cancelar"
          @click="close"
        />
        <UButton
          label="Confirmar atualização"
          icon="i-lucide-refresh-cw"
          :loading="syncConfirming"
          :disabled="!syncPreview?.can_confirm"
          data-testid="mailbox-sync-confirm"
          @click="confirmSync"
        />
      </template>
    </UModal>

    <!-- Inbox abaixo do navbar; navegação fiscal fica no sidebar. -->
    <div class="flex min-h-0 w-full flex-1">
      <UDashboardPanel
        id="mailbox-list"
        :resizable="detailPaneVisible"
        :default-size="detailPaneVisible ? 30 : undefined"
        :min-size="detailPaneVisible ? 22 : undefined"
        :max-size="detailPaneVisible ? 40 : undefined"
        :class="detailPaneVisible ? 'min-h-0' : 'min-h-0 flex-1'"
      >
        <template #header>
          <!--
            Faixa compacta de filtros + presets (surface monitoring.mailbox).
            Sem reescrever a lista mestre–detalhe em ModuleTable.
          -->
          <UDashboardToolbar>
            <template #left>
              <div
                class="flex min-w-0 flex-1 flex-col gap-2"
                data-testid="mailbox-filter-strip"
              >
                <div data-testid="mailbox-triage-filter">
                  <MonitoringModuleToolbar
                    surface="monitoring.mailbox"
                    :filters="filters"
                    :filter-config="filterConfig"
                    :loading="loading"
                    :total="total"
                    :show-total="false"
                    :reset-key="sessionEpoch"
                    @apply-filters="applyModuleFilters"
                    @reset-filters="resetModuleFilters"
                    @refresh="load"
                  />
                </div>
              </div>
            </template>
          </UDashboardToolbar>
        </template>

        <template #body>
          <UAlert
            v-if="loadError"
            color="error"
            icon="i-lucide-circle-x"
            :title="loadError"
            class="mb-3"
          >
            <template #actions>
              <UButton
                size="xs"
                color="neutral"
                variant="outline"
                label="Tentar de novo"
                @click="load"
              />
            </template>
          </UAlert>

          <!-- Lista sempre montada (casca + empty interno); erro em alerta acima -->
          <MonitoringMailboxList
            ref="listRef"
            :messages="rows"
            :selected-id="selectedId"
            :loading="loading"
            @select="selectMessage"
          />

          <ShellTableFooter
            :total="total"
            :page="page"
            :items-per-page="perPage"
            per-page-aria-label="Mensagens por página"
            test-id="mailbox-pagination"
            @update:page="page = $event"
            @update:items-per-page="setPerPage"
          >
            <span class="tabular-nums">{{ total }}</span> mensagem(ns)
            <template v-if="lastPage > 1">
              <span class="text-dimmed"> · </span>
              página {{ page }} de {{ lastPage }}
            </template>
          </ShellTableFooter>
        </template>
      </UDashboardPanel>

      <!-- Desktop: detalhe adjacente via NuxtPage -->
      <div
        v-if="detailPaneVisible"
        class="hidden min-h-0 min-w-0 flex-1 lg:flex"
        data-testid="mailbox-detail-pane"
      >
        <NuxtPage
          @close="closeDetail"
          @triaged="onTriaged"
        />
      </div>

      <!-- Mobile: slideover com detalhe -->
      <ClientOnly>
        <USlideover
          v-if="isMobile"
          v-model:open="mobileOpen"
          :ui="{ content: 'max-w-full' }"
        >
          <template #content>
            <MonitoringMailboxMail
              v-if="selectedId"
              :message-id="selectedId"
              show-close
              @close="closeDetail"
              @triaged="onTriaged"
            />
          </template>
        </USlideover>
      </ClientOnly>
    </div>
  </div>
</template>
