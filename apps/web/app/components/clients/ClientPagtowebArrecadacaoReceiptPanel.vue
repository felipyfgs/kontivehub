<script setup lang="ts">
import type { PagtowebArrecadacaoReceipt, PagtowebArrecadacaoReceiptHistoryPayload } from '~/types/fiscal-modules'
import { usePagtowebArrecadacaoReceipt } from '~/composables/usePagtowebArrecadacaoReceipt'
import { formatDateTime } from '~/utils/format'

const props = defineProps<{ clientId: number, canConsult: boolean }>()
const toast = useToast()
const { fetchHistory, requestReceipt, downloadPath } = usePagtowebArrecadacaoReceipt()
const loading = ref(true)
const requesting = ref(false)
const confirmOpen = ref(false)
const numeroDocumento = ref('')
const error = ref<string | null>(null)
const history = ref<PagtowebArrecadacaoReceiptHistoryPayload | null>(null)
let generation = 0

const receipts = computed(() => history.value?.items || [])
const maskedNumber = computed(() => {
  const value = numeroDocumento.value.trim()
  if (value.length <= 4) return '••••'
  return `${'•'.repeat(Math.max(4, value.length - 4))}${value.slice(-4)}`
})

function provenanceLabel(value?: string | null): string {
  return value === 'SERPRO_TRIAL'
    ? 'Demonstração SERPRO (Trial)'
    : value === 'SERPRO_REAL'
      ? 'SERPRO real — pendente de canário'
      : 'Origem não informada'
}

async function load() {
  const requestGeneration = ++generation
  loading.value = true
  error.value = null
  try {
    const payload = await fetchHistory(props.clientId)
    if (requestGeneration === generation) history.value = payload
  } catch (caught) {
    if (requestGeneration !== generation) return
    history.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível carregar os comprovantes.')
  } finally {
    if (requestGeneration === generation) loading.value = false
  }
}

function openConfirmation() {
  if (!props.canConsult || requesting.value || !numeroDocumento.value.trim()) return
  confirmOpen.value = true
}

async function confirmRequest() {
  if (!props.canConsult || requesting.value) return
  requesting.value = true
  try {
    await requestReceipt(props.clientId, numeroDocumento.value.trim())
    numeroDocumento.value = ''
    confirmOpen.value = false
    toast.add({ title: 'Comprovante solicitado', color: 'success', icon: 'i-lucide-file-check-2' })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível solicitar o comprovante.'), color: 'error' })
  } finally {
    requesting.value = false
  }
}

function download(receipt: PagtowebArrecadacaoReceipt) {
  window.location.assign(downloadPath(props.clientId, receipt.id))
}

watch(() => props.clientId, () => void load(), { immediate: true })
</script>

<template>
  <UPageCard
    title="Comprovante de arrecadação"
    description="Consulta manual e histórico local do comprovante PAGTOWEB, sem expor o documento no painel."
    icon="i-lucide-receipt-text"
    variant="subtle"
    data-testid="client-pagtoweb-arrecadacao-receipt-panel"
  >
    <div class="space-y-4">
      <UAlert color="warning" icon="i-lucide-circle-alert" title="Consulta potencialmente bilhetável" />
      <p class="text-sm text-muted">
        Informe o número do documento apenas para esta solicitação. Ele não é guardado no histórico nem enviado para uma fila.
      </p>

      <UFormField label="Número do documento" name="numeroDocumento" :error="numeroDocumento && numeroDocumento.length > 17 ? 'Informe até 17 caracteres.' : undefined">
        <UInput
          v-model="numeroDocumento"
          maxlength="17"
          autocomplete="off"
          :disabled="!canConsult || requesting"
          placeholder="Digite o número do documento"
        />
      </UFormField>

      <UAlert
        v-if="!canConsult"
        color="neutral"
        icon="i-lucide-lock"
        title="Solicitação indisponível para seu perfil"
      />
      <UAlert
        v-if="error"
        color="error"
        icon="i-lucide-circle-x"
        title="Histórico indisponível"
      >
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
      <p v-if="error" class="text-sm text-error">
        {{ error }}
      </p>

      <div v-else-if="loading" class="space-y-3" aria-label="Carregando comprovantes">
        <USkeleton class="h-8 w-52" />
        <USkeleton class="h-20 w-full" />
      </div>
      <UEmpty
        v-else-if="!receipts.length"
        icon="i-lucide-receipt-text"
        title="Nenhum comprovante disponível"
        description="Ainda não há comprovante de arrecadação registrado para este cliente."
        :ui="{ root: 'py-4' }"
      />
      <ul v-else class="space-y-2" aria-label="Comprovantes de arrecadação">
        <li v-for="receipt in receipts" :key="receipt.id" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-default p-3">
          <div>
            <p class="font-medium text-highlighted">
              Comprovante disponível
            </p>
            <p class="text-xs text-muted">
              {{ formatDateTime(receipt.observed_at) }} · {{ provenanceLabel(receipt.source_provenance) }}
            </p>
          </div>
          <UButton
            color="neutral"
            variant="soft"
            icon="i-lucide-download"
            label="Baixar PDF"
            @click="download(receipt)"
          />
        </li>
      </ul>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center gap-2">
        <UButton
          color="neutral"
          variant="outline"
          icon="i-lucide-refresh-cw"
          label="Atualizar histórico"
          :loading="loading"
          @click="load"
        />
        <UButton
          v-if="canConsult"
          color="primary"
          icon="i-lucide-file-plus-2"
          label="Solicitar comprovante"
          :disabled="!numeroDocumento.trim() || numeroDocumento.length > 17"
          :loading="requesting"
          @click="openConfirmation"
        />
      </div>
    </template>
  </UPageCard>

  <ShellConfirmModal
    v-model:open="confirmOpen"
    title="Confirmar solicitação"
    description="A consulta será enviada agora e pode consumir uma chamada do serviço."
    content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
    confirm-label="Confirmar solicitação"
    confirm-icon="i-lucide-check"
    :loading="requesting"
    @confirm="confirmRequest"
  >
    <template #body>
      <div class="space-y-3">
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Consulta manual confirmada"
        />
        <p class="text-sm text-muted">
          Número informado: <span class="font-medium text-highlighted">{{ maskedNumber }}</span>
        </p>
        <p class="text-sm text-muted">
          O número será usado somente nesta requisição. O PDF ficará disponível no histórico se a resposta for válida.
        </p>
      </div>
    </template>
  </ShellConfirmModal>
</template>
