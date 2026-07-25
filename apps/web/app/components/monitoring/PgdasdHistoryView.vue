<script setup lang="ts">
import type {
  PgdasdHistoryDeclaration,
  PgdasdHistoryPayload,
  PgdasdHistoryPeriod
} from '~/types/fiscal-modules'
import PgdasdHistoryPeriodGrid from './pgdasd/PgdasdHistoryPeriodGrid.vue'
import { usePgdasdMonitoring } from '~/composables/usePgdasdMonitoring'
import { apiErrorMessage } from '~/utils/api-error'
import { formatDateTime } from '~/utils/format'
import {
  formatPgdasdPeriod,
  pgdasdDeclarationMeta,
  pgdasdHistoryCalendarYears,
  pgdasdHistoryPeriods
} from '~/utils/pgdasd'

const props = defineProps<{
  clientId: number
  canCollectDocuments?: boolean
}>()

const { fetchHistory, collectDocuments } = usePgdasdMonitoring()
const toast = useToast()

const loading = ref(false)
const collectingPeriod = ref<string | null>(null)
const error = ref<string | null>(null)
const history = ref<PgdasdHistoryPayload | PgdasdHistoryPeriod[] | null>(null)
const yearFilter = ref<number | 'all'>(new Date().getFullYear())
const knownYears = ref<number[]>([new Date().getFullYear()])
const documentConfirmOpen = ref(false)
const pendingDocumentRequest = ref<{ periodKey: string, declarationNumber: string | null } | null>(null)
let requestGeneration = 0

const payload = computed<PgdasdHistoryPayload>(() =>
  Array.isArray(history.value) ? { periods: history.value } : history.value || {}
)

const yearOptions = computed(() => knownYears.value)

const yearSelectItems = computed(() => [
  { label: 'Todos', value: 'all' as const },
  ...yearOptions.value.map(y => ({ label: String(y), value: y }))
])

const periods = computed(() =>
  [...pgdasdHistoryPeriods(history.value)].sort((a, b) =>
    String(b.period_key || '').localeCompare(String(a.period_key || ''))
  )
)

function rememberYearsFromHistory(payload: PgdasdHistoryPayload | PgdasdHistoryPeriod[] | null) {
  knownYears.value = pgdasdHistoryCalendarYears(payload, knownYears.value)
}

function resetYearFilter() {
  const current = new Date().getFullYear()
  yearFilter.value = current
  knownYears.value = [current]
}

const stateMeta = computed(() => pgdasdDeclarationMeta(payload.value.declaration_state))
const summaryStateMeta = computed(() =>
  loading.value
    ? { color: 'neutral' as const, icon: 'i-lucide-loader-circle', label: 'Carregando histórico' }
    : stateMeta.value
)

function periodKeyFallback(period: PgdasdHistoryPeriod): string {
  return period.period_key || `periodo-${period.declarations?.length || 0}-${period.das?.length || 0}`
}

async function loadHistory() {
  const clientId = props.clientId
  if (!clientId) return
  const generation = ++requestGeneration
  loading.value = true
  error.value = null
  try {
    const params = yearFilter.value === 'all' ? undefined : { year: yearFilter.value }
    const response = await fetchHistory(clientId, params)
    if (generation !== requestGeneration) return
    history.value = response
    rememberYearsFromHistory(response)
  } catch (caught) {
    if (generation !== requestGeneration) return
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico local.')
    history.value = null
  } finally {
    if (generation === requestGeneration) loading.value = false
  }
}

watch(
  () => props.clientId,
  (clientId) => {
    if (clientId) {
      const current = new Date().getFullYear()
      knownYears.value = [current]
      if (yearFilter.value === current) {
        void loadHistory()
      } else {
        yearFilter.value = current
      }
      return
    }
    requestGeneration += 1
    history.value = null
    error.value = null
    loading.value = false
    documentConfirmOpen.value = false
    pendingDocumentRequest.value = null
    resetYearFilter()
  },
  { immediate: true }
)

watch(yearFilter, () => {
  if (props.clientId) void loadHistory()
})

onBeforeUnmount(() => {
  requestGeneration += 1
})

function latestDeclaration(period: PgdasdHistoryPeriod): PgdasdHistoryDeclaration | null {
  return [...(period.declarations || [])].sort((a, b) => {
    const byDate = String(b.transmitted_at || '').localeCompare(String(a.transmitted_at || ''))
    if (byDate !== 0) return byDate
    return String(b.declaration_number || b.number || '')
      .localeCompare(String(a.declaration_number || a.number || ''))
  })[0] || null
}

function openDocumentConfirmation(periodKey: string, declarationNumber: string | null = null) {
  if (!props.canCollectDocuments || !props.clientId || !periodKey) return
  pendingDocumentRequest.value = { periodKey, declarationNumber }
  documentConfirmOpen.value = true
}

function requestDocuments(period: PgdasdHistoryPeriod) {
  const declaration = latestDeclaration(period)
  openDocumentConfirmation(
    period.period_key || '',
    declaration?.declaration_number || declaration?.number || null
  )
}

const pendingOperationLabel = computed(() =>
  pendingDocumentRequest.value?.declarationNumber
    ? 'recibo da declaração observada'
    : 'última declaração e recibo do período'
)

const pendingPeriodLabel = computed(() =>
  formatPgdasdPeriod(pendingDocumentRequest.value?.periodKey)
)

const pendingClientLabel = computed(() =>
  payload.value.client?.legal_name?.trim()
  || `Cliente #${props.clientId || '—'}`
)

const documentsAcknowledged = ref(false)

watch(documentConfirmOpen, (open) => {
  if (!open) documentsAcknowledged.value = false
})

function closeDocumentConfirmation() {
  documentConfirmOpen.value = false
  documentsAcknowledged.value = false
}

async function confirmDocumentCollection() {
  const request = pendingDocumentRequest.value
  if (!props.canCollectDocuments || !props.clientId || !request) return
  collectingPeriod.value = request.periodKey
  try {
    await collectDocuments(props.clientId, {
      period_key: request.periodKey,
      declaration_number: request.declarationNumber,
      confirmed: true
    })
    toast.add({
      title: 'Consulta de documentos enfileirada.',
      description: 'Esta foi uma ação explícita. O histórico será atualizado após o processamento.',
      color: 'success'
    })
    documentConfirmOpen.value = false
    pendingDocumentRequest.value = null
    documentsAcknowledged.value = false
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível solicitar os documentos.'),
      color: 'error'
    })
  } finally {
    collectingPeriod.value = null
  }
}
</script>

<template>
  <div class="w-full min-w-0 max-w-full space-y-4" data-testid="pgdasd-history-view">
    <UAlert
      v-if="error"
      color="error"
      icon="i-lucide-circle-x"
      :title="error"
    >
      <template #actions>
        <UButton
          size="xs"
          color="neutral"
          variant="outline"
          label="Tentar novamente"
          @click="loadHistory"
        />
      </template>
    </UAlert>

    <UPageCard
      title="PGDAS-D"
      variant="subtle"
      class="w-full min-w-0 max-w-full overflow-hidden"
      :ui="{
        container: 'min-w-0 gap-y-5 p-3 sm:p-4',
        wrapper: 'w-full min-w-0',
        body: 'w-full min-w-0'
      }"
      data-testid="pgdasd-compact-summary-card"
    >
      <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
          <UBadge
            :color="summaryStateMeta.color"
            :icon="summaryStateMeta.icon"
            :label="summaryStateMeta.label"
            variant="subtle"
          />
          <span v-if="payload.expected_period_key">
            PA {{ formatPgdasdPeriod(payload.expected_period_key) }}
          </span>
          <span v-if="payload.last_valid_query_at">
            Consulta {{ formatDateTime(payload.last_valid_query_at) }}
          </span>
        </div>
        <UFormField
          label="Ano"
          class="w-full shrink-0 sm:w-36"
        >
          <USelect
            v-model="yearFilter"
            :items="yearSelectItems"
            data-testid="pgdasd-history-year"
          />
        </UFormField>
      </div>

      <div
        v-if="loading"
        class="space-y-3"
        aria-label="Carregando histórico local"
        aria-live="polite"
      >
        <USkeleton class="h-12 w-full" />
        <USkeleton class="h-56 w-full" />
      </div>

      <template v-else-if="history">
        <div
          v-if="periods.length"
          class="w-full min-w-0 max-w-full space-y-3"
          data-testid="pgdasd-history-periods"
          role="list"
          aria-label="Histórico PGDAS-D por período de apuração"
        >
          <PgdasdHistoryPeriodGrid
            v-for="period in periods"
            :key="period.period_key || periodKeyFallback(period)"
            :period="period"
          >
            <template #actions>
              <UButton
                v-if="canCollectDocuments"
                size="sm"
                color="neutral"
                variant="outline"
                icon="i-lucide-cloud-download"
                class="w-full shrink-0 sm:w-auto"
                label="Buscar documentos"
                :aria-label="`Buscar documentos de ${formatPgdasdPeriod(period.period_key)}`"
                :loading="collectingPeriod === period.period_key"
                :disabled="!period.period_key || collectingPeriod != null"
                @click="requestDocuments(period)"
              />
            </template>
          </PgdasdHistoryPeriodGrid>
        </div>

        <div v-else class="rounded-lg border border-dashed border-default px-4 py-12 text-center">
          <div class="mx-auto mb-3 flex size-11 items-center justify-center rounded-full bg-elevated text-dimmed">
            <UIcon name="i-lucide-file-clock" class="size-5" />
          </div>
          <p class="font-medium text-highlighted">
            Nenhum histórico
          </p>
          <p class="mx-auto mt-1 max-w-md text-sm text-muted">
            <template v-if="yearFilter !== 'all'">
              Sem períodos para {{ yearFilter }}.
            </template>
            <template v-else>
              Sem períodos locais.
            </template>
          </p>
          <UButton
            v-if="canCollectDocuments && payload.expected_period_key"
            class="mt-4 w-full sm:w-auto"
            color="primary"
            icon="i-lucide-cloud-download"
            :loading="collectingPeriod === payload.expected_period_key"
            label="Buscar declaração e recibo"
            @click="openDocumentConfirmation(payload.expected_period_key)"
          />
        </div>
      </template>
    </UPageCard>
  </div>

  <ShellScrollableModal
    v-model:open="documentConfirmOpen"
    title="Buscar declaração e recibo"
    :description="`${pendingClientLabel} · PA ${pendingPeriodLabel}`"
    content-class="w-[calc(100vw-1rem)] sm:max-w-xl"
    :dismissible="collectingPeriod == null"
    :show-default-footer="false"
    @cancel="closeDocumentConfirmation"
  >
    <template #body>
      <div class="space-y-5">
        <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-primary">
          <UIcon name="i-lucide-file-search" class="size-4 shrink-0" />
          PGDAS-D
        </div>

        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Consulta manual e potencialmente faturável"
        />

        <div class="rounded-lg border border-default bg-elevated/30 p-3">
          <p class="text-xs font-medium uppercase tracking-wide text-muted">
            Solicitação
          </p>
          <p class="mt-1 text-sm font-medium text-highlighted">
            Buscar {{ pendingOperationLabel }}
          </p>
          <p class="mt-1 text-xs text-muted">
            Os PDFs encontrados ficam protegidos no cofre e aparecem neste histórico quando o processamento terminar.
          </p>
        </div>

        <UCheckbox
          v-model="documentsAcknowledged"
          label="Entendo que esta ação solicita uma consulta para este cliente e período."
          :disabled="collectingPeriod != null"
        />
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        cancel-label="Cancelar"
        submit-label="Solicitar documentos"
        submit-icon="i-lucide-check"
        :loading="collectingPeriod != null"
        :disabled="!documentsAcknowledged"
        :cancel-disabled="collectingPeriod != null"
        @cancel="closeDocumentConfirmation"
        @submit="confirmDocumentCollection"
      />
    </template>
  </ShellScrollableModal>
</template>
