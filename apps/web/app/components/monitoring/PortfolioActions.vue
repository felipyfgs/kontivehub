<script setup lang="ts">
/**
 * Ações reais da carteira (não decorativas):
 * Adicionar cliente · Associar categorias · Solicitar consulta · Exportar
 * Visibilidade por papel (ADMIN/OPERATOR/VIEWER).
 */
import type { DropdownMenuItem } from '@nuxt/ui'
import type { FiscalModuleKey } from '~/types/fiscal-modules'

const props = withDefaults(defineProps<{
  moduleKey: FiscalModuleKey | string
  /** client_id do filtro da página (para enqueue). */
  clientId?: string | number | null
  competence?: string | null
  situation?: string | null
  q?: string | null
  submodule?: string | null
  /** Exibe botão de associação. */
  showAssociate?: boolean
  showEnqueue?: boolean
  showExport?: boolean
  showAddClient?: boolean
  /** Mantém as ações no menu mesmo em viewport desktop (painéis mestres estreitos). */
  compact?: boolean
}>(), {
  showAssociate: true,
  showEnqueue: true,
  showExport: true,
  showAddClient: true
})

const emit = defineEmits<{
  refreshed: []
}>()

const {
  canManageClients,
  canAssociateCategories,
  canTriggerSync,
  canCreateExport,
  enqueueing,
  exporting,
  addClient,
  enqueueReadUpdate,
  exportPortfolio,
  moduleSupportsEnqueueRead,
  moduleSupportsPortfolioExport
} = useMonitoringActions(computed(() => props.moduleKey))

const associateOpen = ref(false)

const clientIdNum = computed(() => {
  const n = Number(props.clientId)
  return Number.isFinite(n) && n > 0 ? n : null
})

/** Mapeia chaves de UI (dctfweb, guides…) para module_key do inventário SERPRO. */
const portfolioModuleKey = computed(() => {
  const key = String(props.moduleKey || '')
  switch (key) {
    case 'simples_mei':
      return 'simples_mei'
    case 'dctfweb':
      return 'dctfweb'
    case 'sitfis':
      return 'sitfis'
    case 'mailbox':
      return 'mailbox'
    case 'guides':
      return 'guides'
    case 'installments':
      return 'installments'
    case 'registrations':
      return 'registrations'
    case 'tax_processes':
      return 'tax_processes'
    case 'declarations':
      return 'declarations'
    default:
      return key || null
  }
})

const showAdd = computed(() => props.showAddClient && canManageClients.value)
const showAssoc = computed(() => props.showAssociate && canAssociateCategories.value)
const showEnq = computed(() =>
  props.showEnqueue
  && canTriggerSync.value
  && moduleSupportsEnqueueRead.value
)
const showExp = computed(() =>
  props.showExport
  && canCreateExport.value
  && moduleSupportsPortfolioExport.value
)

const recentConfirmOpen = ref(false)
const pendingForce = ref(false)

async function runEnqueue(force = false) {
  if (!clientIdNum.value) {
    useToast().add({
      title: 'Informe o cliente (filtro Cliente ID) para solicitar consulta.',
      color: 'warning'
    })
    return
  }
  const result = await enqueueReadUpdate({
    client_id: clientIdNum.value,
    competence: props.competence || undefined,
    force
  })
  recentConfirmOpen.value = false
  if (result) emit('refreshed')
}

async function onEnqueue() {
  // Confirmação informada quando o usuário re-solicita (pode consumir franquia).
  // O modal é opcional se o backend sinalizar recência via query futura; por padrão
  // pedimos confirmação leve sempre que já há client filtrado e o módulo é de leitura.
  if (clientIdNum.value) {
    pendingForce.value = false
    recentConfirmOpen.value = true
    return
  }
  await runEnqueue(false)
}

async function onExport() {
  const ok = await exportPortfolio({
    situation: props.situation || undefined,
    competence: props.competence || undefined,
    q: props.q || undefined,
    submodule: props.submodule || undefined,
    client_id: clientIdNum.value || undefined
  })
  if (ok) emit('refreshed')
}

const mobileItems = computed<DropdownMenuItem[]>(() => {
  const items: DropdownMenuItem[] = []

  if (showAdd.value) {
    items.push({
      label: 'Adicionar cliente',
      icon: 'i-lucide-user-plus',
      onSelect: addClient
    })
  }
  if (showAssoc.value) {
    items.push({
      label: 'Associar categorias',
      icon: 'i-lucide-tags',
      onSelect: () => { associateOpen.value = true }
    })
  }
  if (showEnq.value) {
    items.push({
      label: 'Solicitar consulta',
      icon: 'i-lucide-cloud-download',
      disabled: !clientIdNum.value || (props.moduleKey === 'fgts' && !String(props.competence || '').trim()),
      onSelect: onEnqueue
    })
  }
  if (showExp.value) {
    items.push({
      label: 'Exportar carteira',
      icon: 'i-lucide-download',
      onSelect: onExport
    })
  }

  return items
})
</script>

<template>
  <div
    class="flex items-center gap-2"
    data-testid="monitoring-portfolio-actions"
  >
    <div
      v-if="!compact"
      class="hidden items-center gap-2 sm:flex"
    >
      <UButton
        v-if="showAdd"
        color="primary"
        variant="soft"
        icon="i-lucide-user-plus"
        label="Adicionar cliente"
        size="sm"
        data-testid="action-add-client"
        @click="addClient"
      />
      <UButton
        v-if="showAssoc"
        color="neutral"
        variant="outline"
        icon="i-lucide-tags"
        label="Associar categorias"
        size="sm"
        data-testid="action-associate-categories"
        @click="() => { associateOpen = true }"
      />
      <UButton
        v-if="showEnq"
        color="neutral"
        variant="outline"
        icon="i-lucide-cloud-download"
        label="Solicitar consulta"
        size="sm"
        :loading="enqueueing"
        :disabled="!clientIdNum || (moduleKey === 'fgts' && !String(competence || '').trim())"
        data-testid="action-enqueue-read"
        @click="onEnqueue"
      />
      <MonitoringManualConsultCta
        v-if="canTriggerSync && clientIdNum"
        :client-id="clientIdNum"
        :module-key="portfolioModuleKey"
        label="Consulta manual"
        size="sm"
        @refresh="emit('refreshed')"
      />
      <UButton
        v-if="showExp"
        color="neutral"
        variant="outline"
        icon="i-lucide-download"
        label="Exportar carteira"
        size="sm"
        :loading="exporting"
        data-testid="action-export-portfolio"
        @click="onExport"
      />
    </div>

    <UDropdownMenu
      v-if="mobileItems.length"
      :items="mobileItems"
      :content="{ align: 'end' }"
    >
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-ellipsis-vertical"
        aria-label="Ações da carteira"
        :class="compact ? undefined : 'sm:hidden'"
        :loading="enqueueing || exporting"
        data-testid="portfolio-actions-mobile"
      />
    </UDropdownMenu>

    <FiscalAssociateCategoriesModal
      v-if="showAssoc"
      v-model:open="associateOpen"
      :module-key="moduleKey"
      :default-client-ids="clientIdNum ? [clientIdNum] : []"
      @success="emit('refreshed')"
    />

    <MonitoringRecentRefreshConfirmModal
      v-model:open="recentConfirmOpen"
      :loading="enqueueing"
      @confirm="runEnqueue(pendingForce)"
    />
  </div>
</template>
