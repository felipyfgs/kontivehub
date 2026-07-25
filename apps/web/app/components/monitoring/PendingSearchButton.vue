<script setup lang="ts">
import type { FiscalPortfolioModuleKey } from '~/types/fiscal-modules'
import { COMPACT_BUTTON_LABEL_UI } from '~/utils/list-filter-layout'

const props = withDefaults(defineProps<{
  moduleKey: FiscalPortfolioModuleKey
  submodule?: string
  competence?: string
  currentPageClientIds: number[]
  selectedClientIds?: number[]
}>(), {
  submodule: '',
  competence: '',
  selectedClientIds: () => []
})

const emit = defineEmits<{
  submitted: []
}>()

const { canTriggerSync } = useDashboard()
const {
  enqueueing,
  enqueueReadUpdate,
  moduleSupportsEnqueueRead
} = useMonitoringActions(computed(() => props.moduleKey))
const { requestConsult: requestPgmeiConsult } = usePgmeiMonitoring()
const api = useApi()
const toast = useToast()

const open = ref(false)
const queryingPgmei = ref(false)
const normalizedSubmodule = computed(() => String(props.submodule || '').trim().toUpperCase())
const isPgmei = computed(() =>
  props.moduleKey === 'simples_mei' && normalizedSubmodule.value === 'PGMEI'
)
const isMit = computed(() =>
  props.moduleKey === 'dctfweb' && normalizedSubmodule.value === 'MIT'
)
const isDctfweb = computed(() => props.moduleKey === 'dctfweb' && !isMit.value)
const isInstallments = computed(() => props.moduleKey === 'installments')
const isFgts = computed(() => props.moduleKey === 'fgts')
const supportsSearch = computed(() =>
  isPgmei.value || moduleSupportsEnqueueRead.value
)

function uniqueClientIds(values: number[]) {
  return [...new Set(values
    .map(Number)
    .filter(value => Number.isInteger(value) && value > 0))]
    .slice(0, isInstallments.value ? 25 : 100)
}

const targets = computed(() => {
  const selected = uniqueClientIds(props.selectedClientIds)
  if (selected.length > 0) return selected
  return uniqueClientIds(props.currentPageClientIds)
})
const usesSelection = computed(() => uniqueClientIds(props.selectedClientIds).length > 0)
const requiresCompetence = computed(() => props.moduleKey === 'fgts' && !props.competence.trim())
const searching = computed(() => enqueueing.value || queryingPgmei.value)
const visible = computed(() =>
  canTriggerSync.value && supportsSearch.value && targets.value.length > 0
)
const scopeLabel = computed(() => usesSelection.value ? 'selecionado(s)' : 'da página atual')
const currentYear = computed(() => new Date().getFullYear())
const actionLabel = computed(() => {
  if (isInstallments.value) return 'Consultar todos'
  if (isFgts.value) return 'Sincronizar eSocial'
  return isDctfweb.value ? 'Consulta manual' : 'Buscar pendências'
})
const confirmationTitle = computed(() => isDctfweb.value
  ? 'Confirmar consulta manual DCTFWeb'
  : isInstallments.value
    ? 'Confirmar consulta de todos os parcelamentos'
    : isFgts.value
      ? 'Confirmar sincronização eSocial'
      : 'Confirmar busca de pendências')
const refreshTimers: ReturnType<typeof setTimeout>[] = []

function scheduleRefreshes() {
  for (const delay of [2000, 5000, 10000, 20000]) {
    refreshTimers.push(setTimeout(() => emit('submitted'), delay))
  }
}

onBeforeUnmount(() => refreshTimers.splice(0).forEach(timer => clearTimeout(timer)))

function openConfirmation() {
  if (requiresCompetence.value) {
    toast.add({
      title: 'Informe a competência antes de buscar pendências do FGTS.',
      color: 'warning'
    })
    return
  }
  open.value = true
}

async function submitSearch() {
  if (searching.value || targets.value.length === 0) return

  let queued = 0
  let failed = 0

  if (isInstallments.value) {
    queryingPgmei.value = true
    try {
      const response = await api.fiscal.installments.monitorAll({ client_ids: targets.value })
      const feedback = installmentMonitorFeedback(response.data, targets.value.length)
      queued = feedback.accepted
      failed = feedback.failed
    } catch {
      const feedback = installmentMonitorFeedback(null, targets.value.length)
      queued = feedback.accepted
      failed = feedback.failed
    } finally {
      queryingPgmei.value = false
    }
  } else if (isPgmei.value) {
    queryingPgmei.value = true
    try {
      const response = await requestPgmeiConsult(targets.value, currentYear.value)
      queued = Number(response.enqueued_count ?? targets.value.length)
      failed = Math.max(0, targets.value.length - queued)
    } catch {
      failed = targets.value.length
    } finally {
      queryingPgmei.value = false
    }
  } else {
    for (const clientId of targets.value) {
      const result = await enqueueReadUpdate({
        client_id: clientId,
        competence: props.competence || undefined,
        system_code: isMit.value ? 'INTEGRA_MIT' : undefined,
        service_code: isMit.value ? 'MIT' : undefined,
        operation_code: isMit.value ? 'CONSULTAR_APURACAO' : undefined,
        silent: true
      })
      if (result) queued += 1
      else failed += 1
    }
  }

  toast.add({
    title: queued > 0
      ? (isInstallments.value
          ? 'Consulta de parcelamentos solicitada'
          : isFgts.value
            ? 'Sincronização eSocial solicitada'
            : 'Busca de pendências solicitada')
      : 'Nenhuma consulta foi solicitada',
    description: `${queued} run(s) enfileirada(s)${failed ? ` · ${failed} falha(s)` : ''}. Os resultados aparecerão após o processamento.`,
    color: queued > 0 ? (failed ? 'warning' : 'success') : 'error'
  })

  if (queued > 0) {
    open.value = false
    emit('submitted')
    scheduleRefreshes()
  }
}
</script>

<template>
  <UButton
    v-if="visible"
    color="primary"
    variant="soft"
    icon="i-lucide-scan-search"
    :label="actionLabel"
    :aria-label="actionLabel"
    :ui="COMPACT_BUTTON_LABEL_UI"
    :loading="searching"
    data-testid="monitoring-pending-search"
    @click="openConfirmation"
  >
    <template #trailing>
      <UKbd>{{ targets.length }}</UKbd>
    </template>
  </UButton>

  <ShellConfirmModal
    v-if="visible"
    v-model:open="open"
    :title="confirmationTitle"
    :description="isInstallments
      ? `Serão consultadas até oito modalidades produtivas para ${targets.length} cliente(s) ${scopeLabel}.`
      : isFgts
        ? `Serão consultados S-1299 e S-5013 no eSocial BX para ${targets.length} cliente(s) ${scopeLabel}; guia e pagamento não serão consultados.`
        : `Será solicitada uma consulta de leitura para ${targets.length} cliente(s) ${scopeLabel}.`"
    content-class="w-[calc(100vw-1rem)] sm:max-w-lg"
    :confirm-label="isDctfweb || isInstallments ? 'Confirmar consulta' : 'Confirmar busca'"
    confirm-icon="i-lucide-search-check"
    :loading="searching"
    confirm-test-id="monitoring-pending-search-confirm"
    @confirm="submitSearch"
  >
    <template #body>
      <UAlert
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :title="isInstallments
          ? 'Até oito consultas compostas por cliente'
          : isFgts
            ? 'Até quatro acessos oficiais por cliente'
            : 'Uma consulta por cliente'"
      >
        <template #description>
          {{ isInstallments
            ? 'Cada modalidade é processada de forma independente e pode consumir a franquia da integração. PAEX e SIPADE não serão chamadas enquanto estiverem em prospecção.'
            : isFgts
              ? 'Cada tipo de evento pode consumir uma consulta de identificadores e outra de download da cota diária do empregador. S-5003 exige contexto do trabalhador e não é buscado automaticamente.'
              : 'A ação é somente de leitura, mas pode consumir a franquia da integração. Abrir esta confirmação não realiza nenhuma chamada.' }}
        </template>
      </UAlert>
    </template>
  </ShellConfirmModal>
</template>
