<script setup lang="ts">
import type { PagtowebPaymentCountHistoryPayload } from '~/types/fiscal-modules'
import { usePagtowebPaymentCountMonitoring } from '~/composables/usePagtowebPaymentCountMonitoring'
import { formatDateTime } from '~/utils/format'

const props = withDefaults(defineProps<{
  clientId: number
  canConsult: boolean
  showBillingAlert?: boolean
}>(), {
  showBillingAlert: true
})
const toast = useToast()
const { fetchHistory, requestConsult } = usePagtowebPaymentCountMonitoring()
const initialDate = ref('')
const finalDate = ref('')
const revenueCodes = ref('')
const loading = ref(true)
const requesting = ref(false)
const error = ref<string | null>(null)
const history = ref<PagtowebPaymentCountHistoryPayload | null>(null)
const canRequest = computed(() => props.canConsult && !requesting.value && ((initialDate.value !== '' && finalDate.value !== '') || revenueCodes.value.trim() !== ''))
const period = computed(() => {
  const range = history.value?.current?.filter_summary?.intervalo_data_arrecadacao
  if (!range || typeof range !== 'object') return null
  const { dataInicial, dataFinal } = range as { dataInicial?: unknown, dataFinal?: unknown }
  return typeof dataInicial === 'string' && typeof dataFinal === 'string' ? `${dataInicial} a ${dataFinal}` : null
})

function filters(): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  if (initialDate.value && finalDate.value) out.intervalo_data_arrecadacao = { data_inicial: initialDate.value, data_final: finalDate.value }
  const codes = revenueCodes.value.split(',').map(value => value.trim()).filter(Boolean)
  if (codes.length) out.codigo_receita_lista = codes
  return out
}

async function load() {
  loading.value = true
  error.value = null
  try {
    history.value = await fetchHistory(props.clientId)
  } catch (caught) {
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar o histórico de contagens.')
  } finally {
    loading.value = false
  }
}
async function consult() {
  if (!canRequest.value) return
  requesting.value = true
  try {
    await requestConsult(props.clientId, filters())
    toast.add({ title: 'Contagem de pagamentos enfileirada', description: 'A quantidade agregada será atualizada após a execução.', color: 'success', icon: 'i-lucide-clock-3' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível enfileirar a contagem de pagamentos.'), color: 'error' })
  } finally {
    requesting.value = false
  }
}
watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <div class="space-y-4" data-testid="client-pagtoweb-payment-count-panel">
    <UPageCard
      title="Contagem de pagamentos"
      description="Consulta somente a quantidade agregada de documentos pagos. O painel não aceita nem exibe números de documento."
      icon="i-lucide-chart-no-axes-column"
      variant="subtle"
    >
      <template #default>
        <div class="space-y-4">
          <UAlert
            v-if="showBillingAlert"
            color="warning"
            icon="i-lucide-circle-alert"
            title="Consulta potencialmente bilhetável"
          >
            <template #description>
              Na capability real, chamadas em Consultar podem ser faturadas. A confirmação abaixo inicia uma execução controlada.
            </template>
          </UAlert>
          <div class="grid gap-3 sm:grid-cols-2">
            <UFormField name="data_inicial" label="Data inicial">
              <UInput v-model="initialDate" type="date" />
            </UFormField>
            <UFormField name="data_final" label="Data final">
              <UInput v-model="finalDate" type="date" />
            </UFormField>
          </div>
          <UFormField name="codigo_receita_lista" label="Códigos de receita" description="Opcional; separe códigos de até quatro algarismos por vírgula.">
            <UInput v-model="revenueCodes" placeholder="Ex.: 1082, 0561" />
          </UFormField>
          <UAlert
            v-if="error"
            color="error"
            icon="i-lucide-circle-alert"
            title="Histórico indisponível"
          >
            <template #description>
              {{ error }}
            </template>
            <template #actions>
              <UButton
                size="xs"
                color="neutral"
                variant="outline"
                label="Tentar novamente"
                @click="load"
              />
            </template>
          </UAlert>
          <div v-else-if="loading" class="space-y-3" aria-label="Carregando contagem de pagamentos">
            <USkeleton class="h-8 w-48" /><USkeleton class="h-20 w-full" />
          </div>
          <UEmpty
            v-else-if="!history?.current"
            icon="i-lucide-database"
            title="Sem contagem registrada"
            description="Informe um intervalo de arrecadação ou códigos de receita para iniciar a consulta."
            :ui="{ root: 'py-4' }"
          />
          <div v-else class="rounded-md border border-default p-4">
            <p class="text-sm text-muted">
              Última quantidade agregada
            </p><p class="text-3xl font-semibold text-highlighted">
              {{ history.current.payment_count }}
            </p><p v-if="period" class="mt-2 text-sm text-muted">
              Período consultado: {{ period }}
            </p><p class="mt-2 text-xs text-muted">
              Consulta: {{ formatDateTime(history.current.observed_at) }} · origem: {{ history.current.source_provenance || 'local' }}
            </p>
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex flex-wrap gap-3">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-search"
            label="Atualizar histórico"
            :loading="loading"
            @click="load"
          /><UButton
            v-if="canConsult"
            color="primary"
            icon="i-lucide-calculator"
            label="Confirmar contagem"
            :loading="requesting"
            :disabled="!canRequest"
            @click="consult"
          /><p v-else class="text-sm text-muted">
            Seu perfil pode consultar o histórico, mas não iniciar uma nova contagem.
          </p>
        </div>
      </template>
    </UPageCard>
  </div>
</template>
