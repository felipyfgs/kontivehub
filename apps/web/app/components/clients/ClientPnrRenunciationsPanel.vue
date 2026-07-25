<script setup lang="ts">
import type { FiscalPnrRenunciation } from '~/types/fiscal-modules'

const props = defineProps<{ clientId: number, canConsult: boolean }>()
const api = useApi()
const toast = useToast()
const rows = ref<FiscalPnrRenunciation[]>([])
const loading = ref(true)
const requesting = ref(false)
const error = ref<string | null>(null)
const requestId = ref('')
const renunciationId = ref<number | null>(null)

function provenanceLabel(value?: string | null) {
  return value === 'SERPRO_TRIAL'
    ? 'Demonstração SERPRO (Trial)'
    : value === 'SERPRO_REAL'
      ? 'SERPRO real — ainda sem canário de produção'
      : 'Origem não informada'
}

async function load() {
  loading.value = true
  error.value = null
  try {
    rows.value = (await api.fiscal.pnrRenunciations.forClient(props.clientId)).data.renunciations || []
  } catch (caught) {
    rows.value = []
    error.value = apiErrorMessage(caught, 'Não foi possível carregar as renúncias.')
  } finally { loading.value = false }
}
async function history() {
  if (!props.canConsult || requesting.value) return
  requesting.value = true
  try {
    const result = (await api.fiscal.pnrRenunciations.history(props.clientId, {})).data
    if (!result.success) throw new Error(result.error_message)
    toast.add({ title: 'Consulta de renúncias concluída', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível consultar as renúncias.'), color: 'error' })
  } finally { requesting.value = false }
}
async function status() {
  if (!props.canConsult || !requestId.value.trim() || requesting.value) return
  requesting.value = true
  try {
    const result = (await api.fiscal.pnrRenunciations.status(props.clientId, requestId.value)).data
    if (!result.success) throw new Error(result.error_message)
    toast.add({ title: 'Situação consultada', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível consultar a situação.'), color: 'error' })
  } finally { requesting.value = false }
}
async function receipt() {
  if (!props.canConsult || !renunciationId.value || requesting.value) return
  requesting.value = true
  try {
    const result = (await api.fiscal.pnrRenunciations.receipt(props.clientId, renunciationId.value)).data
    if (!result.success) throw new Error(result.error_message)
    toast.add({ title: 'Comprovante protegido no cofre', color: 'success' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível obter o comprovante.'), color: 'error' })
  } finally { requesting.value = false }
}
watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <UPageCard
    title="Renúncias de vínculo"
    description="PNR Contador: histórico e comprovantes sem expor dados fiscais ou segredos."
    icon="i-lucide-unlink"
    variant="subtle"
    data-testid="client-pnr-renunciations-panel"
  >
    <div class="space-y-4">
      <UAlert
        color="warning"
        icon="i-lucide-circle-alert"
        title="Consultas potencialmente bilhetáveis"
      />
      <p class="text-sm text-muted">
        As consultas só começam após sua ação explícita. Solicitar renúncia não está disponível nesta tela.
      </p>
      <UAlert
        v-if="!canConsult"
        color="neutral"
        icon="i-lucide-lock"
        title="Consulta manual indisponível para seu perfil"
      />
      <p v-if="!canConsult" class="text-sm text-muted">
        Você ainda pode consultar as projeções já registradas para este cliente.
      </p>
      <UAlert
        v-if="error"
        color="error"
        title="Histórico indisponível"
      />
      <p v-if="error" class="text-sm text-error">
        {{ error }}
      </p>
      <div v-else-if="loading" class="space-y-3">
        <USkeleton class="h-8 w-48" /><USkeleton class="h-28 w-full" />
      </div>
      <UEmpty
        v-else-if="!rows.length"
        icon="i-lucide-unlink"
        title="Nenhuma renúncia encontrada"
        description="O cliente pode não ter eventos de renúncia; isso não indica falha técnica."
      />
      <div v-else class="space-y-2">
        <div v-for="row in rows" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-default p-3 text-sm">
          <div>
            <p class="font-medium text-highlighted">
              Renúncia {{ row.renunciation_id }}
            </p><p class="text-muted">
              {{ row.status }} · origem: {{ provenanceLabel(row.source_provenance) }}
            </p>
          </div>
          <UBadge :color="row.receipt ? 'success' : 'neutral'" variant="subtle">
            {{ row.receipt ? 'Comprovante disponível' : 'Sem comprovante' }}
          </UBadge>
        </div>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <UFormField label="ID da solicitação">
          <UInput v-model="requestId" placeholder="Informe o protocolo" />
        </UFormField><UFormField label="ID da renúncia">
          <UInput
            v-model.number="renunciationId"
            type="number"
            min="1"
            placeholder="Para comprovante"
          />
        </UFormField>
      </div>
    </div>
    <template #footer>
      <div class="flex flex-wrap gap-2">
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Atualizar histórico"
          :loading="loading"
          @click="load"
        /><UButton
          v-if="canConsult"
          color="primary"
          icon="i-lucide-search"
          label="Confirmar consulta"
          :loading="requesting"
          @click="history"
        /><UButton
          v-if="canConsult"
          color="neutral"
          variant="soft"
          label="Consultar situação"
          :disabled="!requestId.trim()"
          :loading="requesting"
          @click="status"
        /><UButton
          v-if="canConsult"
          color="neutral"
          variant="soft"
          label="Obter comprovante"
          :disabled="!renunciationId"
          :loading="requesting"
          @click="receipt"
        />
      </div>
    </template>
  </UPageCard>
</template>
