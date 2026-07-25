<script setup lang="ts">
import type { MitListaApuracoes317Payload } from '~/types/fiscal-modules'
import { useMitListaApuracoes } from '~/composables/useMitListaApuracoes'
import { apiErrorMessage } from '~/utils/api-error'
import { formatCurrency, formatDate } from '~/utils/format'
import { formatDctfwebPeriod } from '~/utils/dctfweb'

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const props = withDefaults(defineProps<{
  open: boolean
  clientId: number | null
  clientName?: string | null
  cnpjMasked?: string | null
  canConsult?: boolean
}>(), {
  canConsult: false
})

const { fetchLocalList, enqueueList } = useMitListaApuracoes()
const toast = useToast()
const loading = ref(false)
const enqueueing = ref(false)
const error = ref<string | null>(null)
const payload = ref<MitListaApuracoes317Payload | null>(null)
const confirmationOpen = ref(false)
const filters = reactive({
  year: String(new Date().getFullYear()),
  month: '',
  situation: ''
})
let requestGeneration = 0

const apuracoes = computed(() => payload.value?.data || [])
const serproCalled = computed(() => payload.value?.provenance?.serpro_called === true)
const provenanceLabel = computed(() =>
  serproCalled.value ? 'Resultado de consulta registrado' : 'Projeção local'
)
const requestedPeriodLabel = computed(() => {
  const year = filters.year || '—'
  return filters.month ? `${filters.month.padStart(2, '0')}/${year}` : `todas as competências de ${year}`
})

async function loadLocalList() {
  const clientId = props.clientId
  if (!props.open || !clientId) return
  const generation = ++requestGeneration
  loading.value = true
  error.value = null
  try {
    const response = await fetchLocalList(clientId)
    if (generation === requestGeneration) payload.value = response
  } catch (caught) {
    if (generation !== requestGeneration) return
    error.value = apiErrorMessage(caught, 'Não foi possível carregar as apurações MIT locais.')
    payload.value = null
  } finally {
    if (generation === requestGeneration) loading.value = false
  }
}

watch(
  () => [props.open, props.clientId] as const,
  ([open]) => {
    if (open) {
      void loadLocalList()
      return
    }
    requestGeneration += 1
    loading.value = false
    error.value = null
    payload.value = null
  },
  { immediate: true }
)

function openConfirmation() {
  const year = Number(filters.year)
  const month = filters.month === '' ? null : Number(filters.month)
  const situation = filters.situation === '' ? null : Number(filters.situation)
  if (!props.canConsult || !Number.isInteger(year) || year < 2000 || year > 2100) {
    toast.add({ title: 'Informe um ano entre 2000 e 2100.', color: 'warning' })
    return
  }
  if (month !== null && (!Number.isInteger(month) || month < 1 || month > 12)) {
    toast.add({ title: 'Informe um mês entre 1 e 12.', color: 'warning' })
    return
  }
  if (situation !== null && (!Number.isInteger(situation) || situation < 0 || situation > 9999)) {
    toast.add({ title: 'Informe uma situação válida.', color: 'warning' })
    return
  }
  confirmationOpen.value = true
}

async function confirmEnqueue() {
  const clientId = props.clientId
  const year = Number(filters.year)
  if (!props.canConsult || !clientId || !Number.isInteger(year)) return
  enqueueing.value = true
  try {
    await enqueueList(clientId, {
      year,
      ...(filters.month ? { month: Number(filters.month) } : {}),
      ...(filters.situation ? { situation: Number(filters.situation) } : {})
    })
    confirmationOpen.value = false
    toast.add({
      title: 'Atualização das apurações solicitada.',
      description: 'A lista local será atualizada após o processamento.',
      color: 'success'
    })
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível solicitar a atualização das apurações.'),
      color: 'error'
    })
  } finally {
    enqueueing.value = false
  }
}
</script>

<template>
  <ShellScrollableModal
    :open="open"
    title="Apurações MIT 317"
    :description="`${clientName || 'Cliente'}${cnpjMasked ? ` · ${cnpjMasked}` : ''}`"
    content-class="sm:max-w-3xl"
    test-id="mit-lista-apuracoes-modal"
    :show-default-footer="false"
    @update:open="emit('update:open', $event)"
    @cancel="emit('update:open', false)"
  >
    <template #body>
      <div class="space-y-3">
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

        <div v-else class="space-y-3">
          <p class="text-muted text-sm">
            {{ apuracoes.length }} apuração(ões) local(is) retornada(s) pela consulta 317.
          </p>
          <div v-if="canConsult" class="rounded-lg border border-default bg-elevated/30 p-3">
            <div class="flex flex-wrap items-end gap-3">
              <UFormField label="Ano" name="mit-year" class="w-28">
                <UInput
                  v-model="filters.year"
                  type="number"
                  min="2000"
                  max="2100"
                  inputmode="numeric"
                />
              </UFormField>
              <UFormField label="Mês (opcional)" name="mit-month" class="w-36">
                <UInput
                  v-model="filters.month"
                  type="number"
                  min="1"
                  max="12"
                  inputmode="numeric"
                />
              </UFormField>
              <UFormField label="Situação (opcional)" name="mit-situation" class="w-44">
                <UInput
                  v-model="filters.situation"
                  type="number"
                  min="0"
                  max="9999"
                  inputmode="numeric"
                />
              </UFormField>
              <UButton
                color="primary"
                icon="i-lucide-refresh-cw"
                label="Atualizar apurações"
                :disabled="enqueueing"
                @click="openConfirmation"
              />
            </div>
            <p class="mt-2 text-xs text-muted">
              A lista acima permanece local. A atualização só será solicitada após confirmação.
            </p>
          </div>
          <div
            v-if="apuracoes.length === 0"
            class="text-muted text-sm py-6 text-center"
            data-testid="mit-lista-apuracoes-empty"
          >
            Nenhuma apuração MIT 317 local para este cliente.
          </div>
          <div
            v-for="apuracao in apuracoes"
            :key="apuracao.id"
            class="border border-default rounded-lg p-3 space-y-2"
            data-testid="mit-lista-apuracoes-item"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-medium">
                {{ formatDctfwebPeriod(apuracao.period_key) }}
              </span>
              <UBadge
                :label="apuracao.situation || 'Não verificado'"
                color="neutral"
                variant="subtle"
                class="rounded-sm"
              />
            </div>
            <div class="grid gap-1 text-sm text-muted sm:grid-cols-2">
              <span>Encerramento: {{ formatDate(apuracao.lista_apuracoes_317?.data_encerramento) }}</span>
              <span>Valor apurado: {{ formatCurrency(apuracao.lista_apuracoes_317?.valor_total_apurado) }}</span>
              <span>Evento especial: {{ apuracao.lista_apuracoes_317?.evento_especial ? 'Sim' : 'Não' }}</span>
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

  <ShellConfirmModal
    v-model:open="confirmationOpen"
    title="Confirmar atualização das apurações"
    :description="`MIT · ${requestedPeriodLabel}`"
    content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
    confirm-label="Confirmar atualização"
    confirm-icon="i-lucide-check"
    :loading="enqueueing"
    @confirm="confirmEnqueue"
  >
    <template #body>
      <div class="space-y-3">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Consulta manual"
        />
        <p class="text-sm text-muted">
          Esta ação agenda a consulta oficial das apurações MIT. Nenhuma transmissão, encerramento ou emissão de DARF será realizada.
        </p>
      </div>
    </template>
  </ShellConfirmModal>
</template>
