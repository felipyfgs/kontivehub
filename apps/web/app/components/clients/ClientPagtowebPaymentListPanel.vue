<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { PagtowebPaymentListHistoryPayload, PagtowebPaymentListItem } from '~/types/fiscal-modules'
import { usePagtowebPaymentListMonitoring } from '~/composables/usePagtowebPaymentListMonitoring'
import { formatDateTime } from '~/utils/format'
import { DASHBOARD_TABLE_UI, LIST_TABLE_CLASS } from '~/utils/table-ui'

const props = withDefaults(defineProps<{
  clientId: number
  canConsult: boolean
  showBillingAlert?: boolean
}>(), {
  showBillingAlert: true
})
const toast = useToast()
const { fetchHistory, requestConsult } = usePagtowebPaymentListMonitoring()
const initialDate = ref('')
const finalDate = ref('')
const revenueCodes = ref('')
const loading = ref(true)
const requesting = ref(false)
const error = ref<string | null>(null)
const history = ref<PagtowebPaymentListHistoryPayload | null>(null)
const canRequest = computed(() => props.canConsult && !requesting.value && initialDate.value !== '' && finalDate.value !== '')
const period = computed(() => {
  const range = history.value?.current?.filter_summary?.intervalo_data_arrecadacao
  if (!range || typeof range !== 'object') return null
  const { dataInicial, dataFinal } = range as { dataInicial?: unknown, dataFinal?: unknown }
  return typeof dataInicial === 'string' && typeof dataFinal === 'string' ? `${dataInicial} a ${dataFinal}` : null
})
const columns: TableColumn<PagtowebPaymentListItem>[] = [
  { accessorKey: 'document_masked', header: 'Documento' },
  { accessorKey: 'document_type', header: 'Tipo' },
  { accessorKey: 'revenue_code', header: 'Receita' },
  { accessorKey: 'paid_on', header: 'Arrecadação' },
  { accessorKey: 'due_on', header: 'Vencimento' },
  { accessorKey: 'total_amount', header: 'Valor total' }
]

function filters(): Record<string, unknown> {
  const codes = revenueCodes.value.split(',').map(value => value.trim()).filter(Boolean)
  return {
    intervalo_data_arrecadacao: { data_inicial: initialDate.value, data_final: finalDate.value },
    ...(codes.length ? { codigo_receita_lista: codes } : {})
  }
}
async function load() {
  loading.value = true
  error.value = null
  try {
    history.value = await fetchHistory(props.clientId)
  } catch (caught) {
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar os pagamentos consultados.')
  } finally {
    loading.value = false
  }
}
async function consult() {
  if (!canRequest.value) return
  requesting.value = true
  try {
    await requestConsult(props.clientId, filters())
    toast.add({ title: 'Consulta de pagamentos enfileirada', description: 'Atualize o histórico após a execução para ver os documentos mascarados.', color: 'success', icon: 'i-lucide-clock-3' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível enfileirar a consulta de pagamentos.'), color: 'error' })
  } finally {
    requesting.value = false
  }
}
watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <UPageCard
    title="Documentos de pagamentos"
    description="PAGTOWEB 7.1: consulta por período com documentos mascarados para proteger dados fiscais."
    icon="i-lucide-receipt-text"
    variant="subtle"
    data-testid="client-pagtoweb-payment-list-panel"
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
            A consulta só é iniciada após confirmação. Em modo simulado, o resultado será identificado como simulado.
          </template>
        </UAlert>
        <div class="grid gap-3 sm:grid-cols-2">
          <UFormField name="data_inicial_pagamentos" label="Data inicial">
            <UInput v-model="initialDate" type="date" />
          </UFormField>
          <UFormField name="data_final_pagamentos" label="Data final">
            <UInput v-model="finalDate" type="date" />
          </UFormField>
        </div>
        <UFormField name="codigo_receita_pagamentos" label="Códigos de receita" description="Opcional; separe códigos de até quatro algarismos por vírgula.">
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
        <div v-else-if="loading" class="space-y-3" aria-label="Carregando documentos de pagamentos">
          <USkeleton class="h-8 w-48" /><USkeleton class="h-40 w-full" />
        </div>
        <UEmpty
          v-else-if="!history?.current"
          icon="i-lucide-receipt-text"
          title="Nenhum pagamento consultado"
          description="Informe o período para realizar uma consulta controlada."
          :ui="{ root: 'py-4' }"
        />
        <template v-else>
          <div class="rounded-md border border-default p-4 text-sm text-muted">
            <p v-if="period">
              Período consultado: {{ period }}
            </p>
            <p>Resultado: {{ history.current.returned_count || 0 }} documento(s) · origem: {{ history.current.source_provenance || 'local' }}</p>
            <p>Consultado em: {{ formatDateTime(history.current.observed_at) }}</p>
          </div>
          <UTable
            :data="history.items"
            :columns="columns"
            :class="LIST_TABLE_CLASS"
            :ui="DASHBOARD_TABLE_UI"
          >
            <template #empty>
              <UEmpty icon="i-lucide-receipt-text" title="Nenhum documento nesta página" description="A consulta foi concluída sem documentos retornados para os filtros informados." />
            </template>
          </UTable>
        </template>
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
        />
        <UButton
          v-if="canConsult"
          color="primary"
          icon="i-lucide-receipt-text"
          label="Confirmar consulta"
          :loading="requesting"
          :disabled="!canRequest"
          @click="consult"
        />
        <p v-else class="text-sm text-muted">
          Seu perfil pode consultar o histórico, mas não iniciar uma nova consulta.
        </p>
      </div>
    </template>
  </UPageCard>
</template>
