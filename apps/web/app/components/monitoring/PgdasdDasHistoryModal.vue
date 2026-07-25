<script setup lang="ts">
/**
 * Modal “DAS Simples Nacional - Histórico” (nível 1).
 * Histórico local de DAS; abrir não dispara SERPRO.
 */
import type {
  PgdasdArtifactDescriptor,
  PgdasdHistoryDas,
  PgdasdHistoryPayload,
  PgdasdHistoryPeriod
} from '~/types/fiscal-modules'
import { useAuthenticatedDownload } from '~/composables/useAuthenticatedDownload'
import { usePgdasdMonitoring } from '~/composables/usePgdasdMonitoring'
import { apiErrorMessage } from '~/utils/api-error'
import { resolveApiUrl } from '~/utils/api-url'
import { formatAmountCents, formatCurrency, formatDateTime } from '~/utils/format'
import {
  formatPgdasdPeriod,
  pgdasdHistoryPeriods
} from '~/utils/pgdasd'

const props = defineProps<{
  open: boolean
  clientId: number | null
  clientName?: string | null
  cnpjMasked?: string | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { fetchHistory, artifactDownloadUrl } = usePgdasdMonitoring()
const { download: downloadAuthenticated, downloading: downloadBusy } = useAuthenticatedDownload()
const apiBase = useRuntimeConfig().public.apiBase as string

const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<PgdasdHistoryPayload | PgdasdHistoryPeriod[] | null>(null)
const yearFilter = ref<number | 'all'>('all')
let requestGeneration = 0

const payload = computed<PgdasdHistoryPayload>(() =>
  Array.isArray(history.value) ? { periods: history.value } : history.value || {}
)

const allPeriods = computed(() =>
  [...pgdasdHistoryPeriods(history.value)].sort((a, b) =>
    String(b.period_key || '').localeCompare(String(a.period_key || ''))
  )
)

const yearOptions = computed(() => {
  const years = new Set<number>()
  for (const period of allPeriods.value) {
    const match = /^(\d{4})/.exec(String(period.period_key || ''))
    if (match) years.add(Number(match[1]))
  }
  const current = new Date().getFullYear()
  years.add(current)
  return [...years].sort((a, b) => b - a)
})

const periods = computed(() => {
  if (yearFilter.value === 'all') return allPeriods.value
  const year = String(yearFilter.value)
  return allPeriods.value.filter(period => String(period.period_key || '').startsWith(`${year}-`))
})

const clientLabel = computed(() =>
  payload.value.client?.legal_name?.trim()
  || props.clientName?.trim()
  || (props.clientId ? `Cliente #${props.clientId}` : '—')
)

const cnpjLabel = computed(() =>
  payload.value.client?.cnpj_masked?.trim()
  || props.cnpjMasked?.trim()
  || '—'
)

const description = computed(() => `${clientLabel.value} · ${cnpjLabel.value}`)

function periodArtifacts(period: PgdasdHistoryPeriod): PgdasdArtifactDescriptor[] {
  const all = [
    ...(period.artifacts || []),
    ...(period.documents || []),
    ...(period.declarations || []).flatMap(item => item.documents || []),
    ...(period.das || []).flatMap(item => item.documents || [])
  ]
  return [...new Map(all.map(item => [item.id, item])).values()]
}

function artifactHref(artifact: PgdasdArtifactDescriptor | null | undefined): string | null {
  if (!artifact) return null
  const path = artifact.download_path?.trim() || artifact.download_href?.trim()
  if (path) return resolveApiUrl(path, apiBase)
  if (artifact.id) return artifactDownloadUrl(artifact.id)
  return null
}

function dasDownload(period: PgdasdHistoryPeriod): string | null {
  const artifacts = periodArtifacts(period)
  const dasDoc = artifacts.find(a =>
    ['DAS', 'DARF_MAED'].includes(String(a.kind || '').toUpperCase())
  )
  return artifactHref(dasDoc)
}

async function downloadDas(period: PgdasdHistoryPeriod): Promise<void> {
  const href = dasDownload(period)
  if (!href) return
  const artifacts = periodArtifacts(period)
  const dasDoc = artifacts.find(a =>
    ['DAS', 'DARF_MAED'].includes(String(a.kind || '').toUpperCase())
  )
  const filename = dasDoc
    ? `pgdasd-das-${dasDoc.id}.pdf`
    : `pgdasd-das-${period.period_key || 'documento'}.pdf`
  await downloadAuthenticated(href, filename)
}

function maedLabel(period: PgdasdHistoryPeriod): string {
  const has = periodArtifacts(period).some(a =>
    ['NOTIFICACAO_MAED', 'DARF_MAED', 'MAED'].includes(String(a.kind || '').toUpperCase())
  )
  return has ? 'Sim' : '—'
}

function malhaLabel(period: PgdasdHistoryPeriod): string {
  const values = (period.declarations || []).map(d => d.malha).filter(v => v != null && v !== '')
  if (!values.length) return '—'
  const yes = values.some(v =>
    v === true || ['SIM', 'TRUE', '1'].includes(String(v).toUpperCase())
  )
  return yes ? 'Sim' : 'Não'
}

function paymentLabel(period: PgdasdHistoryPeriod): string {
  const dasItems = period.das || []
  if (!dasItems.length) return '—'
  const located = dasItems.some((d: PgdasdHistoryDas) => d.payment_located === true)
  const missing = dasItems.some((d: PgdasdHistoryDas) => d.payment_located === false)
  if (located) return 'Localizado'
  if (missing) return 'Não localizado'
  return '—'
}

function periodKeyFallback(period: PgdasdHistoryPeriod): string {
  return period.period_key || `periodo-${period.declarations?.length || 0}-${period.das?.length || 0}`
}

function totalValue(period: PgdasdHistoryPeriod): string {
  const cents = period.rbt12?.total_cents
  if (typeof cents === 'number' && Number.isFinite(cents)) {
    return formatAmountCents(cents)
  }
  const raw = period.rbt12?.rbt12_value
  if (raw != null && String(raw).trim() !== '') {
    const n = Number(raw)
    return Number.isFinite(n) ? formatCurrency(n) : String(raw)
  }
  return '—'
}

async function loadHistory() {
  const clientId = props.clientId
  if (!props.open || !clientId) return
  const generation = ++requestGeneration
  loading.value = true
  error.value = null
  try {
    const params = yearFilter.value === 'all' ? undefined : { year: yearFilter.value }
    const response = await fetchHistory(clientId, params)
    if (generation === requestGeneration) history.value = response
  } catch (caught) {
    if (generation !== requestGeneration) return
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico local.')
    history.value = null
  } finally {
    if (generation === requestGeneration) loading.value = false
  }
}

watch(
  () => [props.open, props.clientId] as const,
  ([open]) => {
    if (open) {
      yearFilter.value = 'all'
      void loadHistory()
      return
    }
    requestGeneration += 1
    history.value = null
    error.value = null
    loading.value = false
  },
  { immediate: true }
)

watch(yearFilter, () => {
  if (props.open && props.clientId) void loadHistory()
})
</script>

<template>
  <ShellScrollableModal
    :open="open"
    title="DAS Simples Nacional - Histórico"
    :description="description"
    content-class="w-[calc(100vw-1rem)] sm:max-w-6xl"
    test-id="pgdasd-das-history-modal"
    :show-default-footer="false"
    @update:open="emit('update:open', $event)"
    @cancel="emit('update:open', false)"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="MAEDs não são enviadas automaticamente aos clientes"
          description="Notificações MAED permanecem disponíveis para download local; o envio ao contribuinte não é disparado por este histórico."
        />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div class="min-w-0 text-sm">
            <p class="font-medium text-highlighted">
              {{ clientLabel }}
            </p>
            <p class="text-xs text-muted">
              CNPJ {{ cnpjLabel }}
            </p>
          </div>
          <UFormField
            label="Ano da busca"
            class="w-full sm:w-44"
          >
            <USelect
              v-model="yearFilter"
              :items="[
                { label: 'Todos', value: 'all' },
                ...yearOptions.map(y => ({ label: String(y), value: y }))
              ]"
              data-testid="pgdasd-das-history-year"
            />
          </UFormField>
        </div>

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

        <div
          v-if="loading"
          class="space-y-3"
          aria-label="Carregando histórico DAS"
          aria-live="polite"
        >
          <USkeleton class="h-10 w-full" />
          <USkeleton class="h-48 w-full" />
        </div>

        <div
          v-else-if="!periods.length"
          class="rounded-lg border border-dashed border-default px-4 py-12 text-center text-sm text-muted"
        >
          Nenhum período no histórico local
          {{ yearFilter === 'all' ? '' : ` para ${yearFilter}` }}.
        </div>

        <div
          v-else
          class="overflow-x-auto rounded-lg border border-default"
          role="region"
          aria-label="Histórico DAS Simples Nacional"
          data-testid="pgdasd-das-history-table"
        >
          <table class="w-full min-w-[960px] border-separate border-spacing-0 text-left text-sm">
            <thead class="bg-elevated/60 text-xs text-muted">
              <tr>
                <th class="border-b border-default px-3 py-2.5 font-medium">
                  Período
                </th>
                <th class="border-b border-default px-3 py-2.5 font-medium">
                  Pagamento
                </th>
                <th class="border-b border-default px-3 py-2.5 font-medium">
                  Busca
                </th>
                <th class="border-b border-default px-3 py-2.5 font-medium">
                  Valor total
                </th>
                <th class="border-b border-default px-3 py-2.5 font-medium">
                  Vencimento
                </th>
                <th class="border-b border-default px-3 py-2.5 text-center font-medium">
                  Malha
                </th>
                <th class="border-b border-default px-3 py-2.5 text-center font-medium">
                  MAED
                </th>
                <th class="border-b border-default px-3 py-2.5 font-medium">
                  Download
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="period in periods"
                :key="period.period_key || periodKeyFallback(period)"
                class="group"
              >
                <td class="border-b border-default px-3 py-3 font-medium text-highlighted group-hover:bg-elevated/40">
                  {{ formatPgdasdPeriod(period.period_key) }}
                </td>
                <td class="border-b border-default px-3 py-3 text-xs group-hover:bg-elevated/40">
                  {{ paymentLabel(period) }}
                </td>
                <td class="whitespace-nowrap border-b border-default px-3 py-3 text-xs group-hover:bg-elevated/40">
                  {{ formatDateTime(period.last_valid_query_at) }}
                </td>
                <td class="border-b border-default px-3 py-3 tabular-nums group-hover:bg-elevated/40">
                  {{ totalValue(period) }}
                </td>
                <td class="border-b border-default px-3 py-3 text-xs text-muted group-hover:bg-elevated/40">
                  —
                </td>
                <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                  {{ malhaLabel(period) }}
                </td>
                <td class="border-b border-default px-3 py-3 text-center group-hover:bg-elevated/40">
                  {{ maedLabel(period) }}
                </td>
                <td class="border-b border-default px-3 py-3 group-hover:bg-elevated/40">
                  <UButton
                    v-if="dasDownload(period)"
                    size="xs"
                    color="neutral"
                    variant="soft"
                    icon="i-lucide-download"
                    label="Baixar DAS"
                    :loading="downloadBusy"
                    :disabled="downloadBusy"
                    data-testid="pgdasd-das-download"
                    @click="downloadDas(period)"
                  />
                  <span v-else class="text-muted">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </ShellScrollableModal>
</template>
