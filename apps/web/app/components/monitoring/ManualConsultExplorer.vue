<script setup lang="ts">
/**
 * Explorador de consultas manuais no dashboard de monitoramento.
 * Lista inventário local (GET) + modal de confirmação (POST confirmed).
 */
import type { ManualConsultAction } from '~/types/fiscal-modules'
import { apiErrorMessage } from '~/utils/api-error'

const props = withDefaults(defineProps<{
  /** Pré-filtra por module_key do inventário. */
  moduleKey?: string | null
  surfaceKey?: string | null
  /** Cliente inicial (opcional). */
  initialClientId?: number | null
  compact?: boolean
}>(), {
  moduleKey: null,
  surfaceKey: null,
  initialClientId: null,
  compact: false
})

const emit = defineEmits<{
  executed: [payload: { action_id: string, client_id: number, module_route: string }]
}>()

const { canTriggerSync } = useDashboard()
const {
  loading,
  executing,
  loadError,
  actions,
  readyCount,
  totalCount,
  loadInventory,
  execute
} = useManualConsultExplorer()
const api = useApi()
const toast = useToast()

const clientId = ref<number | undefined>(props.initialClientId && props.initialClientId > 0
  ? props.initialClientId
  : undefined)
const ALL_MODULES_VALUE = '__all__'
const moduleFilter = ref(props.moduleKey || ALL_MODULES_VALUE)
const clientOptions = ref<Array<{ label: string, value: number }>>([])
const loadingClients = ref(false)

const confirmOpen = ref(false)
const selected = ref<ManualConsultAction | null>(null)
const paramValues = ref<Record<string, string>>({})

function closeConfirm() {
  confirmOpen.value = false
}

const moduleOptions = [
  // O Select do Reka UI reserva `''` para limpar o valor; itens nunca podem usá-lo.
  { label: 'Todos os módulos', value: ALL_MODULES_VALUE },
  { label: 'Simples Nacional | MEI', value: 'simples_mei' },
  { label: 'DCTFWeb', value: 'dctfweb' },
  { label: 'SITFIS', value: 'sitfis' },
  { label: 'Caixa Postal', value: 'mailbox' },
  { label: 'Guias', value: 'guides' },
  { label: 'Parcelamentos', value: 'installments' },
  { label: 'Cadastros', value: 'registrations' },
  { label: 'Processos', value: 'tax_processes' }
]

const filteredActions = computed(() => {
  let list = actions.value
  if (moduleFilter.value !== ALL_MODULES_VALUE) {
    list = list.filter(a => a.module_key === moduleFilter.value)
  }
  return list
})

async function loadClients() {
  loadingClients.value = true
  try {
    const res = await api.clients.list({ per_page: 100 })
    const rows = (res.data || []) as Array<{ id: number, name?: string, trade_name?: string, root_cnpj?: string }>
    clientOptions.value = rows.map(c => ({
      value: c.id,
      label: `${c.trade_name || c.name || `Cliente #${c.id}`}${c.root_cnpj ? ` · ${c.root_cnpj}` : ''}`
    }))
  } catch (caught) {
    clientOptions.value = []
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível listar clientes.'),
      color: 'warning'
    })
  } finally {
    loadingClients.value = false
  }
}

async function refresh() {
  await loadInventory({
    client_id: clientId.value ?? undefined,
    surface_key: props.surfaceKey || undefined,
    module_key: moduleFilter.value === ALL_MODULES_VALUE ? undefined : moduleFilter.value
  })
}

function openConfirm(action: ManualConsultAction) {
  if (!canTriggerSync.value) {
    toast.add({ title: 'Sem permissão para enfileirar consultas.', color: 'warning' })
    return
  }
  if (!action.executable) {
    toast.add({
      title: 'Consulta indisponível',
      description: action.eligibility_label,
      color: 'warning'
    })
    return
  }
  if (requiresStructuredModuleUi(action)) {
    toast.add({
      title: 'Use os filtros da tela do módulo',
      description: 'Esta consulta exige filtros estruturados e não aceita payload livre.',
      color: 'warning'
    })
    return
  }
  if (!clientId.value) {
    toast.add({ title: 'Selecione um cliente antes de consultar.', color: 'warning' })
    return
  }
  selected.value = action
  const defaults: Record<string, string> = {}
  for (const field of action.params_schema || []) {
    defaults[field.name] = ''
  }
  paramValues.value = defaults
  confirmOpen.value = true
}

function buildParams(action: ManualConsultAction): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const field of action.params_schema || []) {
    const raw = paramValues.value[field.name]
    if (raw === undefined || raw === null || String(raw).trim() === '') {
      if (field.required) {
        throw new Error(`Informe: ${field.label}`)
      }
      continue
    }
    if (field.type === 'object') {
      throw new Error('Abra o módulo para preencher os filtros estruturados desta consulta.')
    } else if (field.type === 'integer') {
      out[field.name] = Number(raw)
    } else {
      out[field.name] = String(raw)
    }
  }
  return out
}

function requiresStructuredModuleUi(action: ManualConsultAction): boolean {
  return action.params_schema.some(field => field.type === 'object')
}

function actionControlTitle(action: ManualConsultAction): string {
  if (!action.executable) return action.eligibility_label
  if (requiresStructuredModuleUi(action)) return 'Preencha os filtros estruturados na tela do módulo'
  return 'Confirmar consulta de leitura bilhetável'
}

async function confirmExecute() {
  if (!selected.value || !clientId.value) return
  let params: Record<string, unknown>
  try {
    params = buildParams(selected.value)
  } catch (err) {
    toast.add({
      title: err instanceof Error ? err.message : 'Parâmetros inválidos',
      color: 'warning'
    })
    return
  }

  const result = await execute({
    action_id: selected.value.action_id,
    client_id: clientId.value,
    params
  })
  if (result) {
    confirmOpen.value = false
    emit('executed', {
      action_id: result.action_id,
      client_id: clientId.value,
      module_route: result.module_route
    })
    await refresh()
  }
}

function eligibilityColor(action: ManualConsultAction): 'success' | 'warning' | 'error' | 'neutral' {
  if (action.executable) return 'success'
  if (action.eligibility === 'adapter_missing' || action.eligibility === 'permission_denied') return 'neutral'
  if (action.eligibility === 'capability_off' || action.eligibility === 'module_off') return 'warning'
  return 'error'
}

watch([clientId, moduleFilter], () => {
  void refresh()
})

onMounted(async () => {
  await Promise.all([loadClients(), refresh()])
})
</script>

<template>
  <UCard
    class="shrink-0"
    data-testid="manual-consult-explorer"
    :ui="{ body: props.compact ? 'p-3 sm:p-4' : 'p-4 sm:p-6' }"
  >
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <h2 class="text-sm font-semibold text-highlighted">
            Consultas manuais
          </h2>
          <p class="text-xs text-muted">
            Inventário local · bilhetagem só após confirmação
            <span v-if="totalCount"> · {{ readyCount }}/{{ totalCount }} prontas</span>
          </p>
        </div>
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-refresh-cw"
          square
          aria-label="Atualizar inventário"
          :loading="loading"
          data-testid="manual-consult-refresh"
          @click="refresh"
        />
      </div>
    </template>

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
      <UFormField label="Cliente">
        <USelect
          v-model="clientId"
          :items="clientOptions"
          value-key="value"
          label-key="label"
          placeholder="Selecione o cliente"
          :loading="loadingClients"
          class="w-full"
          data-testid="manual-consult-client"
        />
      </UFormField>
      <UFormField label="Módulo">
        <USelect
          v-model="moduleFilter"
          :items="moduleOptions"
          value-key="value"
          label-key="label"
          class="w-full"
          data-testid="manual-consult-module"
        />
      </UFormField>
    </div>

    <UAlert
      v-if="loadError"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="loadError"
      class="mb-3"
    />

    <div
      v-if="loading && !filteredActions.length"
      class="py-8 text-center text-sm text-muted"
    >
      Carregando inventário…
    </div>
    <div
      v-else-if="!filteredActions.length"
      class="py-8 text-center text-sm text-muted"
      data-testid="manual-consult-empty"
    >
      Nenhuma consulta de leitura disponível neste recorte.
    </div>
    <ul
      v-else
      class="divide-y divide-default rounded-lg border border-default"
      data-testid="manual-consult-list"
    >
      <li
        v-for="action in filteredActions"
        :key="action.action_id"
        class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5"
        :data-testid="`manual-consult-row-${action.action_id}`"
      >
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-highlighted">
            {{ action.label }}
          </p>
          <p class="truncate text-xs text-muted">
            {{ action.module_route }}
          </p>
          <MonitoringQueryStateBadge
            v-if="action.last_result_summary"
            :projection="action.last_result_summary"
            class="mt-1"
          />
        </div>
        <div class="flex items-center gap-2">
          <UBadge
            :color="eligibilityColor(action)"
            variant="subtle"
            size="sm"
          >
            {{ action.eligibility_label }}
          </UBadge>
          <UBadge
            v-if="requiresStructuredModuleUi(action)"
            color="neutral"
            variant="outline"
            size="sm"
            label="Filtros na tela do módulo"
          />
          <UButton
            v-if="canTriggerSync"
            size="sm"
            color="primary"
            variant="soft"
            icon="i-lucide-search"
            label="Consultar"
            :disabled="!action.executable || !clientId || requiresStructuredModuleUi(action)"
            :title="actionControlTitle(action)"
            data-testid="manual-consult-run"
            @click="openConfirm(action)"
          />
          <UButton
            v-if="action.module_route"
            size="sm"
            color="neutral"
            variant="ghost"
            icon="i-lucide-external-link"
            square
            :to="action.module_route"
            aria-label="Abrir módulo"
          />
        </div>
      </li>
    </ul>

    <ShellFormModal
      v-model:open="confirmOpen"
      title="Confirmar consulta manual"
      description="Esta ação pode consumir a franquia da integração SERPRO. O histórico local só atualiza após o job."
      :show-default-footer="false"
      @cancel="closeConfirm"
    >
      <template #body>
        <div
          v-if="selected"
          class="space-y-3"
          data-testid="manual-consult-confirm"
        >
          <p class="text-sm text-highlighted">
            {{ selected.label }}
          </p>
          <p class="text-xs text-muted">
            Cliente #{{ clientId }} · {{ selected.eligibility_label }}
          </p>
          <div
            v-for="field in selected.params_schema.filter(item => item.type !== 'object')"
            :key="field.name"
            class="space-y-1"
          >
            <UFormField
              :label="field.label"
              :required="field.required"
            >
              <UInput
                v-model="paramValues[field.name]"
                class="w-full"
                :placeholder="field.pattern || field.type"
              />
            </UFormField>
          </div>
        </div>
      </template>
      <template #footer>
        <ShellModalFooter
          submit-label="Confirmar consulta"
          submit-icon="i-lucide-check"
          :loading="executing"
          submit-test-id="manual-consult-confirm-submit"
          @cancel="closeConfirm"
          @submit="confirmExecute"
        />
      </template>
    </ShellFormModal>
  </UCard>
</template>
