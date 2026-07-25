<script setup lang="ts">
/**
 * Ações PGMEI da seleção: Solicitar consulta (com confirmação) + Limpar.
 * Membership (associar/excluir) fica fora — modal dedicado / ações da linha.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'

const props = defineProps<{
  selectedClientIds: number[]
  selectedCount: number
  year: number
  canUsePublicServices?: boolean
}>()

const emit = defineEmits<{
  'clear': []
  'refresh': []
  'publicServices': [clientId: number]
  'consult-enqueued': [runs: Array<{ clientId: number, runId: number }>]
}>()

const { requestConsult } = usePgmeiMonitoring()
const toast = useToast()
const querying = ref(false)
const confirmOpen = ref(false)

const visible = computed(() => props.selectedCount > 0)

function validateSelection(): boolean {
  if (props.selectedCount < 1) return false
  if (props.selectedClientIds.length > 100) {
    toast.add({ title: 'Selecione no máximo 100 clientes.', color: 'warning' })
    return false
  }
  return true
}

function openQueryConfirmation() {
  if (!validateSelection()) return
  confirmOpen.value = true
}

async function confirmQuery() {
  if (querying.value || !validateSelection()) return
  querying.value = true
  try {
    const response = await requestConsult(props.selectedClientIds, props.year)
    const count = Number(response.enqueued_count ?? props.selectedClientIds.length)
    const enqueued = (response.data || [])
      .map((run) => {
        const runId = Number(run.id)
        const clientId = Number(run.client_id)
        if (!Number.isFinite(runId) || runId < 1 || !Number.isFinite(clientId) || clientId < 1) {
          return null
        }
        return { clientId, runId }
      })
      .filter((entry): entry is { clientId: number, runId: number } => entry != null)
    toast.add({
      title: `${count} consulta(s) PGMEI solicitada(s).`,
      description: `Ano-calendário ${props.year}. A atualização aparecerá após o processamento.`,
      color: 'success'
    })
    confirmOpen.value = false
    if (enqueued.length) emit('consult-enqueued', enqueued)
    emit('clear')
    emit('refresh')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível solicitar as consultas.'),
      color: 'error'
    })
  } finally {
    querying.value = false
  }
}

const items = computed<DropdownMenuItem[][]>(() => {
  if (!visible.value) return []
  const disabled = querying.value
  const actions: DropdownMenuItem[] = [
    {
      label: 'Solicitar consulta',
      icon: 'i-lucide-cloud-download',
      disabled,
      onSelect: () => openQueryConfirmation()
    }
  ]

  if (props.selectedCount === 1 && props.canUsePublicServices) {
    const clientId = props.selectedClientIds[0]!
    actions.push({
      label: 'Serviços MEI',
      icon: 'i-lucide-landmark',
      disabled,
      onSelect: () => emit('publicServices', clientId)
    })
  }

  return [
    actions,
    [{
      label: 'Limpar seleção',
      icon: 'i-lucide-x',
      disabled,
      onSelect: () => emit('clear')
    }]
  ]
})
</script>

<template>
  <div
    v-if="visible"
    data-testid="pgmei-bulk-actions"
  >
    <UDropdownMenu
      :items="items"
      :content="{ align: 'start' }"
    >
      <UButton
        color="neutral"
        variant="subtle"
        icon="i-lucide-list-checks"
        label="Ações"
        aria-label="Ações em massa"
        :ui="COMPACT_BUTTON_LABEL_UI"
        :loading="querying"
        data-testid="pgmei-bulk-actions-menu"
      >
        <template #trailing>
          <UKbd>{{ selectedCount }}</UKbd>
        </template>
      </UButton>
    </UDropdownMenu>
  </div>

  <ShellConfirmModal
    v-model:open="confirmOpen"
    title="Confirmar consulta de dívida ativa"
    :description="`A consulta à SERPRO para ${selectedCount} cliente(s), ano ${year}, é explícita e pode ser faturável.`"
    content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
    confirm-label="Confirmar consulta"
    confirm-icon="i-lucide-cloud-download"
    :loading="querying"
    @confirm="confirmQuery"
  >
    <template #body>
      <UAlert
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="Uma chamada por cliente selecionado"
      >
        <template #description>
          Abrir esta confirmação não consulta a SERPRO. A chamada ocorrerá somente ao confirmar.
        </template>
      </UAlert>
    </template>
  </ShellConfirmModal>
</template>
