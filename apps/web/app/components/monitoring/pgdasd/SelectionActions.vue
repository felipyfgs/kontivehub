<script setup lang="ts">
/**
 * Ações PGDAS-D da seleção: Solicitar consulta (com confirmação) + Limpar.
 * Membership (associar/excluir) fica fora — modal dedicado / ações da linha.
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import {
  buildPgdasdSelectionMenu,
  type PgdasdActionHandlers
} from '~/utils/pgdasd-action-items'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'

const props = defineProps<{
  selectedClientIds: number[]
  selectedCount: number
  handlers?: PgdasdActionHandlers
  canConsult?: boolean
}>()

const emit = defineEmits<{
  'clear': []
  'refresh': []
  'consult-enqueued': [runs: Array<{ clientId: number, runId: number }>]
}>()

const { enqueueReadUpdate, canTriggerSync } = useMonitoringActions('simples_mei')
const toast = useToast()

const busy = ref(false)
const pgdasdConfirmOpen = ref(false)

const showConsult = computed(() => props.canConsult !== false && canTriggerSync.value)
const visible = computed(() => props.selectedCount > 0)

const items = computed<DropdownMenuItem[][]>(() => {
  if (!visible.value) return []
  return buildPgdasdSelectionMenu({
    clientIds: props.selectedClientIds,
    handlers: {
      onConsult: showConsult.value ? openPgdasdConsultConfirm : undefined
    },
    onClear: () => emit('clear'),
    busy: busy.value
  })
})

function openPgdasdConsultConfirm() {
  if (!showConsult.value || !props.selectedCount || busy.value) return
  if (props.selectedClientIds.length > 100) {
    toast.add({ title: 'Selecione no máximo 100 clientes.', color: 'warning' })
    return
  }
  pgdasdConfirmOpen.value = true
}

async function confirmPgdasdConsult() {
  if (!showConsult.value || busy.value || !props.selectedClientIds.length) return
  busy.value = true
  let ok = 0
  let fail = 0
  const enqueued: Array<{ clientId: number, runId: number }> = []
  try {
    for (const clientId of props.selectedClientIds) {
      const run = await enqueueReadUpdate({ client_id: clientId, silent: true })
      if (run && typeof run === 'object' && 'id' in run) {
        const runId = Number((run as { id?: number }).id)
        if (Number.isFinite(runId) && runId > 0) {
          enqueued.push({ clientId, runId })
          ok++
          continue
        }
      }
      if (run) ok++
      else fail++
    }
    toast.add({
      title: 'Consultas PGDAS-D enfileiradas',
      description: `${ok} ok${fail ? ` · ${fail} falha(s)` : ''} de ${props.selectedClientIds.length}`,
      color: fail && !ok ? 'error' : fail ? 'warning' : 'success'
    })
    pgdasdConfirmOpen.value = false
    if (ok) {
      if (enqueued.length) emit('consult-enqueued', enqueued)
      emit('clear')
      emit('refresh')
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div
    v-if="visible"
    data-testid="pgdasd-selection-actions"
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
        :loading="busy"
        data-testid="pgdasd-selection-actions-menu"
      >
        <template #trailing>
          <UKbd>{{ selectedCount }}</UKbd>
        </template>
      </UButton>
    </UDropdownMenu>

    <ShellConfirmModal
      v-model:open="pgdasdConfirmOpen"
      title="Confirmar consulta PGDAS-D"
      :description="`Será enfileirada 1 consulta PGDAS-D por cliente (${selectedCount}). Pode ser faturável.`"
      content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
      confirm-label="Confirmar consulta"
      confirm-icon="i-lucide-cloud-download"
      :loading="busy"
      confirm-test-id="pgdasd-bulk-consult-confirm"
      @confirm="confirmPgdasdConsult"
    >
      <template #body>
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Uma chamada por CNPJ (Integra Contador)"
        >
          <template #description>
            O hub enfileira MONITOR PGDAS-D por contribuinte selecionado.
          </template>
        </UAlert>
      </template>
    </ShellConfirmModal>
  </div>
</template>
