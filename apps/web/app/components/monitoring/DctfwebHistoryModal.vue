<script setup lang="ts">
import type {
  DctfwebEvidenceDescriptor,
  DctfwebHistoryPayload,
  DctfwebHistoryPeriod
} from '~/types/fiscal-modules'
import { useDctfwebMonitoring } from '~/composables/useDctfwebMonitoring'
import { apiErrorMessage } from '~/utils/api-error'
import { resolveApiUrl } from '~/utils/api-url'
import { formatBytes, formatDateTime } from '~/utils/format'
import {
  dctfwebDeclarationMeta,
  dctfwebHistoryPeriods,
  formatDctfwebPeriod
} from '~/utils/dctfweb'

const props = defineProps<{
  open: boolean
  clientId: number | null
  clientName?: string | null
  cnpjMasked?: string | null
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { fetchHistory, evidenceDownloadUrl } = useDctfwebMonitoring()
const apiBase = useRuntimeConfig().public.apiBase as string

const loading = ref(false)
const error = ref<string | null>(null)
const history = ref<DctfwebHistoryPayload | null>(null)
let requestGeneration = 0

const payload = computed(() => history.value || {})
const periods = computed(() =>
  [...dctfwebHistoryPeriods(history.value)].sort((a, b) =>
    String(b.period_key || '').localeCompare(String(a.period_key || ''))
  )
)
const stateMeta = computed(() => dctfwebDeclarationMeta(payload.value.declaration_state))
const serproCalled = computed(() => payload.value.provenance?.serpro_called === true)
const provenanceLabel = computed(() =>
  serproCalled.value ? 'Resultado de consulta registrado' : 'Projeção local'
)

async function loadHistory() {
  const clientId = props.clientId
  if (!props.open || !clientId) return
  const generation = ++requestGeneration
  loading.value = true
  error.value = null
  try {
    const response = await fetchHistory(clientId)
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

function docsOf(period: DctfwebHistoryPeriod): DctfwebEvidenceDescriptor[] {
  const entries = [
    ...(period.documents || []),
    ...(period.artifacts || [])
  ]

  return [...new Map(entries.map(document => [document.id, document])).values()]
}

function documentDownloadUrl(doc: DctfwebEvidenceDescriptor): string | undefined {
  const path = doc.download_path?.trim()
  if (path) return resolveApiUrl(path, apiBase)
  if (!props.clientId || !doc.id) return undefined
  return evidenceDownloadUrl(props.clientId, doc.id)
}
</script>

<template>
  <ShellScrollableModal
    :open="open"
    title="Histórico de busca DCTFWeb"
    :description="`${clientName || 'Cliente'}${cnpjMasked ? ` · ${cnpjMasked}` : ''}`"
    content-class="sm:max-w-3xl"
    test-id="dctfweb-history-modal"
    :show-default-footer="false"
    @update:open="emit('update:open', $event)"
    @cancel="emit('update:open', false)"
  >
    <template #body>
      <div class="space-y-4">
        <p class="text-muted text-xs">
          Origem: {{ provenanceLabel }}
        </p>

        <ShellLoadingModalBody v-if="loading" :rows="3" />

        <UAlert
          v-else-if="error"
          color="error"
          icon="i-lucide-triangle-alert"
          :title="error"
        />

        <div
          v-else
          class="space-y-4"
        >
          <div class="flex flex-wrap items-center gap-2">
            <UBadge
              :label="stateMeta.label"
              :color="stateMeta.color"
              :icon="stateMeta.icon"
              variant="subtle"
              class="rounded-sm"
            />
            <span class="text-muted text-sm">
              PA esperado: {{ formatDctfwebPeriod(payload.expected_period_key) }}
            </span>
            <span
              v-if="payload.last_valid_query_at"
              class="text-muted text-sm"
            >
              · Última busca: {{ formatDateTime(payload.last_valid_query_at) }}
            </span>
          </div>

          <div
            v-if="periods.length === 0"
            class="text-muted text-sm py-6 text-center"
          >
            Nenhum histórico local para este cliente.
          </div>

          <div
            v-for="period in periods"
            :key="String(period.period_key)"
            class="border border-default rounded-lg p-3 space-y-2"
            data-testid="dctfweb-history-period"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="font-medium">
                {{ formatDctfwebPeriod(period.period_key) }}
              </div>
              <UBadge
                v-if="period.declaration_state"
                :label="dctfwebDeclarationMeta(period.declaration_state).label"
                :color="dctfwebDeclarationMeta(period.declaration_state).color"
                variant="subtle"
                size="sm"
                class="rounded-sm"
              />
            </div>

            <div
              v-if="(period.observations || []).length"
              class="text-sm text-muted"
            >
              {{ (period.observations || []).length }} observação(ões) de consulta
            </div>

            <div
              v-if="docsOf(period).length"
              class="flex flex-wrap gap-2"
            >
              <UButton
                v-for="doc in docsOf(period)"
                :key="doc.id"
                size="xs"
                color="neutral"
                variant="outline"
                icon="i-lucide-download"
                :label="`${doc.filename || doc.kind || doc.content_type || 'Documento'}${doc.byte_size ? ` · ${formatBytes(doc.byte_size)}` : ''}`"
                :to="documentDownloadUrl(doc)"
                target="_blank"
                external
                data-testid="dctfweb-download-evidence"
              />
            </div>
          </div>
        </div>
      </div>
    </template>
    <template #footer>
      <ShellModalFooter
        cancel-label="Fechar"
        :show-submit="false"
        @cancel="emit('update:open', false)"
      />
    </template>
  </ShellScrollableModal>
</template>
