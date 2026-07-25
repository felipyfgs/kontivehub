<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { SitfisHistoryPayload, SitfisHistorySearch } from '~/types/fiscal-modules'
import { useAuthenticatedDownload } from '~/composables/useAuthenticatedDownload'
import { useSitfisMonitoring } from '~/composables/useSitfisMonitoring'
import { apiErrorMessage } from '~/utils/api-error'
import { formatDate } from '~/utils/format'

const props = defineProps<{
  clientId: number
  clientName?: string | null
  cnpjMasked?: string | null
}>()

const { fetchHistory } = useSitfisMonitoring()
const { download } = useAuthenticatedDownload()

const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<SitfisHistoryPayload | null>(null)
const downloadingSearchId = ref<number | null>(null)
let requestGeneration = 0

const searches = computed(() => history.value?.searches || [])
const displayedName = computed(() => history.value?.client.legal_name || props.clientName || `Cliente #${props.clientId}`)
const displayedCnpj = computed(() => history.value?.client.cnpj_masked || props.cnpjMasked || null)

const columns: TableColumn<SitfisHistorySearch>[] = [
  { accessorKey: 'observed_at', header: 'Data da Busca' },
  { id: 'file', header: 'Arquivo' }
]

async function loadHistory() {
  if (!props.clientId) return
  const generation = ++requestGeneration
  loading.value = true
  error.value = null
  try {
    const response = await fetchHistory(props.clientId)
    if (generation === requestGeneration) history.value = response
  } catch (caught) {
    if (generation !== requestGeneration) return
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico de busca SITFIS.')
    history.value = null
  } finally {
    if (generation === requestGeneration) loading.value = false
  }
}

watch(
  () => props.clientId,
  (clientId) => {
    requestGeneration += 1
    history.value = null
    error.value = null
    if (clientId) void loadHistory()
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  requestGeneration += 1
})

async function downloadReport(search: SitfisHistorySearch) {
  const path = sitfisHistoryDownloadPath(search)
  if (!path) return
  downloadingSearchId.value = search.id
  try {
    await download(path, sitfisHistoryFilename(search))
  } finally {
    downloadingSearchId.value = null
  }
}
</script>

<template>
  <UPageCard
    title="Histórico de Busca"
    variant="subtle"
    data-testid="sitfis-history-view"
  >
    <div class="space-y-4">
      <div class="flex items-center gap-3 rounded-lg border border-default bg-elevated p-3">
        <UIcon name="i-lucide-building-2" class="size-5 shrink-0 text-muted" />
        <div class="min-w-0">
          <p class="truncate text-sm font-medium text-highlighted">
            {{ displayedName }}
          </p>
          <p v-if="displayedCnpj" class="text-xs tabular-nums text-muted">
            {{ displayedCnpj }}
          </p>
        </div>
      </div>

      <UAlert
        v-if="error"
        color="error"
        icon="i-lucide-triangle-alert"
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

      <ShellDataTable
        v-else
        ui-preset="monitoring-compact"
        :data="searches"
        :columns="columns"
        :loading="loading"
        :page="1"
        :total="searches.length"
        :items-per-page="searches.length || 1"
        :show-footer="false"
        :mobile-cards="false"
        empty-title="Nenhuma busca SITFIS registrada"
        empty-description="As consultas concluídas aparecerão aqui sem iniciar uma nova busca."
        test-id="sitfis-history-table"
      >
        <template #observed_at-cell="{ row }">
          <span class="tabular-nums">
            {{ formatDate(row.original.observed_at) }}
          </span>
        </template>

        <template #file-cell="{ row }">
          <div class="flex justify-end">
            <UButton
              v-if="sitfisHistoryDownloadPath(row.original)"
              size="xs"
              color="primary"
              variant="solid"
              icon="i-lucide-download"
              label="Baixar relatório"
              :loading="downloadingSearchId === row.original.id"
              :aria-label="`Baixar relatório SITFIS de ${formatDate(row.original.observed_at)}`"
              data-testid="sitfis-history-download"
              @click="downloadReport(row.original)"
            />
            <span v-else class="text-xs text-muted" data-testid="sitfis-history-file-unavailable">
              Arquivo indisponível
            </span>
          </div>
        </template>
      </ShellDataTable>
    </div>
  </UPageCard>
</template>
