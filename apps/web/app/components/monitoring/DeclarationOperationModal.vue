<script setup lang="ts">
import type {
  DeclarationOperation,
  DeclarationsSubmodule
} from '~/types/fiscal-modules'
import {
  buildDeclarationOperationParams,
  declarationMutationStatusMeta,
  declarationOperationAvailabilityMeta,
  declarationOperationDefaults
} from '~/utils/declaration-operations'

const open = defineModel<boolean>('open', { default: false })

const props = withDefaults(defineProps<{
  obligation: DeclarationsSubmodule
  operations?: DeclarationOperation[]
  initialClientId?: number | null
  initialClientName?: string | null
  initialActionId?: string | null
}>(), {
  operations: () => [],
  initialClientId: null,
  initialClientName: null,
  initialActionId: null
})

const emit = defineEmits<{
  executed: []
}>()

const api = useApi()
const toast = useToast()
const { canTriggerSync, canExecuteHighRiskMutation } = useDashboard()
const {
  busy,
  polling,
  error,
  preflight,
  mutation,
  readResult,
  reset: resetWorkflow,
  runRead,
  requestPreflight,
  executeMutation,
  reconcile
} = useDeclarationOperations()

const clientId = ref<number | undefined>()
const clientOptions = ref<Array<{ label: string, value: number }>>([])
const clientsLoading = ref(false)
const selectedActionId = ref<string | null>(null)
const search = ref('')
const flowFilter = ref<'ALL' | 'READ' | 'MUTATION' | 'PROSPECTION'>('ALL')
const paramValues = ref<Record<string, string>>({})
const password = ref('')
const confirmationInput = ref('')
const explicitlyConfirmed = ref(false)
let settingDefaults = false

const flowItems = [
  { label: 'Todas', value: 'ALL' },
  { label: 'Consultas', value: 'READ' },
  { label: 'Controladas', value: 'MUTATION' },
  { label: 'Prospecção', value: 'PROSPECTION' }
]

const filteredOperations = computed(() => {
  const query = search.value.trim().toLocaleLowerCase('pt-BR')
  return props.operations.filter((operation) => {
    if (flowFilter.value === 'PROSPECTION' && operation.official_state !== 'PROSPECTION') return false
    if (flowFilter.value === 'READ' && (operation.flow !== 'READ' || operation.official_state === 'PROSPECTION')) return false
    if (flowFilter.value === 'MUTATION' && (operation.flow !== 'MUTATION' || operation.official_state === 'PROSPECTION')) return false
    return !query || `${operation.label} ${operation.official_route}`.toLocaleLowerCase('pt-BR').includes(query)
  })
})

const selected = computed(() => props.operations.find(
  operation => operation.action_id === selectedActionId.value
) || null)

const selectedAvailability = computed(() => declarationOperationAvailabilityMeta(selected.value?.availability))
const mutationStatus = computed(() => declarationMutationStatusMeta(mutation.value))
const requiresTextarea = (type: string) => ['object', 'array', 'base64'].includes(type)
const inputType = (type: string) => type === 'month' || type === 'date' ? type : 'text'

const permissionDenied = computed(() => {
  if (!selected.value) return 'Selecione uma operação.'
  if (!selected.value.executable) return selectedAvailability.value.label
  if (selected.value.flow === 'READ' && !canTriggerSync.value) return 'Sem permissão para disparar consultas.'
  if (selected.value.flow === 'MUTATION' && !canExecuteHighRiskMutation.value) return 'Somente perfil autorizado pode executar mutações.'
  return null
})

const primaryLabel = computed(() => {
  if (selected.value?.flow === 'READ') return 'Confirmar consulta'
  if (!preflight.value?.eligible) return 'Verificar e preparar'
  return 'Executar operação'
})

const primaryIcon = computed(() => {
  if (selected.value?.flow === 'READ') return 'i-lucide-search-check'
  if (!preflight.value?.eligible) return 'i-lucide-shield-check'
  return 'i-lucide-send'
})

const confirmationReady = computed(() => {
  if (selected.value?.flow !== 'MUTATION' || !preflight.value?.eligible) return true
  return explicitlyConfirmed.value
    && password.value.trim().length > 0
    && confirmationInput.value.trim() === String(preflight.value.confirmation_phrase || '')
})

const primaryDisabled = computed(() => Boolean(
  busy.value
  || !clientId.value
  || permissionDenied.value
  || !confirmationReady.value
))

const readResultJson = computed(() => readResult.value?.result
  ? JSON.stringify(readResult.value.result, null, 2)
  : null)

async function loadClients() {
  clientsLoading.value = true
  try {
    const response = await api.clients.list({ per_page: 100 })
    const rows = response.data as Array<{
      id: number
      name?: string | null
      legal_name?: string | null
      trade_name?: string | null
      cnpj_masked?: string | null
      root_cnpj?: string | null
    }>
    clientOptions.value = rows.map(client => ({
      value: client.id,
      label: `${client.trade_name || client.legal_name || client.name || `Cliente #${client.id}`}${client.cnpj_masked || client.root_cnpj ? ` · ${client.cnpj_masked || client.root_cnpj}` : ''}`
    }))
    if (props.initialClientId && !clientOptions.value.some(item => item.value === props.initialClientId)) {
      clientOptions.value.unshift({
        value: props.initialClientId,
        label: props.initialClientName || `Cliente #${props.initialClientId}`
      })
    }
  } catch (caught) {
    clientOptions.value = props.initialClientId
      ? [{ value: props.initialClientId, label: props.initialClientName || `Cliente #${props.initialClientId}` }]
      : []
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível listar os clientes.'),
      color: 'warning'
    })
  } finally {
    clientsLoading.value = false
  }
}

function selectOperation(operation: DeclarationOperation) {
  selectedActionId.value = operation.action_id
}

function resetConfirmation() {
  password.value = ''
  confirmationInput.value = ''
  explicitlyConfirmed.value = false
}

function initializeSelected() {
  const preferred = props.operations.find(operation => operation.action_id === props.initialActionId)
    || props.operations.find(operation => operation.executable)
    || props.operations[0]
    || null
  selectedActionId.value = preferred?.action_id || null
}

function params(): Record<string, unknown> | null {
  if (!selected.value) return null
  try {
    return buildDeclarationOperationParams(selected.value.params, paramValues.value)
  } catch (caught) {
    toast.add({
      title: caught instanceof Error ? caught.message : 'Revise os parâmetros da operação.',
      color: 'warning'
    })
    return null
  }
}

async function submit() {
  if (!selected.value || !clientId.value || primaryDisabled.value) return
  const payload = params()
  if (!payload) return

  if (selected.value.flow === 'READ') {
    const result = await runRead(selected.value, clientId.value, payload)
    if (result) {
      toast.add({
        title: result.async ? 'Consulta enfileirada' : 'Consulta registrada',
        description: 'A projeção local será atualizada quando a fonte responder.',
        color: 'success'
      })
      emit('executed')
    }
    return
  }

  if (!preflight.value?.eligible) {
    const prepared = await requestPreflight(selected.value, clientId.value, payload)
    if (prepared?.eligible) {
      toast.add({ title: 'Preflight aprovado. Confirme a consequência.', color: 'success' })
    } else if (prepared) {
      toast.add({
        title: prepared.denial_message || prepared.eligibility?.messages?.[0] || 'Operação bloqueada pelos controles atuais.',
        color: 'warning'
      })
    }
    return
  }

  const result = await executeMutation({
    operation: selected.value,
    clientId: clientId.value,
    params: payload,
    password: password.value.trim(),
    confirmationPhrase: confirmationInput.value.trim()
  })
  if (result) {
    password.value = ''
    toast.add({ title: 'Operação registrada', description: result.status_label || result.status, color: 'success' })
    emit('executed')
  }
}

async function reconcileResult() {
  if (!password.value.trim()) {
    toast.add({ title: 'Informe sua senha para reconciliar.', color: 'warning' })
    return
  }
  const result = await reconcile(password.value.trim())
  if (result) {
    password.value = ''
    toast.add({ title: 'Reconciliação solicitada', description: result.status_label || result.status, color: 'success' })
    emit('executed')
  }
}

function closeModal() {
  open.value = false
}

watch(selected, (operation) => {
  settingDefaults = true
  paramValues.value = operation ? declarationOperationDefaults(operation) : {}
  resetWorkflow()
  resetConfirmation()
  nextTick(() => {
    settingDefaults = false
  })
})

watch(paramValues, () => {
  if (settingDefaults || (!preflight.value && !mutation.value && !readResult.value)) return
  resetWorkflow()
  resetConfirmation()
}, { deep: true })

watch(clientId, () => {
  resetWorkflow()
  resetConfirmation()
})

watch(open, async (isOpen) => {
  if (!isOpen) {
    resetWorkflow()
    return
  }
  clientId.value = props.initialClientId || undefined
  search.value = ''
  flowFilter.value = 'ALL'
  selectedActionId.value = null
  initializeSelected()
  await loadClients()
})
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    :title="`Central de operações · ${obligation}`"
    description="A abertura é local. Uma chamada oficial só ocorre após seleção, validação e confirmação explícita."
    content-class="sm:max-w-6xl"
    :show-default-footer="false"
    test-id="declaration-operation-modal"
  >
    <template #body>
      <div class="grid min-h-[28rem] gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
        <aside class="flex min-h-0 flex-col gap-3 rounded-xl border border-default bg-elevated/30 p-3">
          <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-2">
            <UInput
              v-model="search"
              icon="i-lucide-search"
              placeholder="Filtrar operação"
              size="sm"
              data-testid="declaration-operation-search"
            />
            <USelect
              v-model="flowFilter"
              :items="flowItems"
              value-key="value"
              label-key="label"
              size="sm"
              data-testid="declaration-operation-flow-filter"
            />
          </div>

          <div class="min-h-0 flex-1 space-y-1 overflow-y-auto pr-1" data-testid="declaration-operation-list">
            <button
              v-for="operation in filteredOperations"
              :key="operation.action_id"
              type="button"
              class="w-full rounded-lg border px-3 py-2 text-left transition"
              :class="selectedActionId === operation.action_id ? 'border-primary bg-primary/10' : 'border-transparent hover:border-default hover:bg-elevated'"
              :data-testid="`declaration-operation-${operation.action_id}`"
              @click="selectOperation(operation)"
            >
              <span class="block text-xs font-medium text-highlighted">{{ operation.label }}</span>
              <span class="mt-1 flex flex-wrap items-center gap-1">
                <UBadge
                  :label="operation.flow === 'READ' ? 'Consulta' : 'Controlada'"
                  :color="operation.flow === 'READ' ? 'info' : 'warning'"
                  variant="subtle"
                  size="sm"
                />
                <UBadge
                  v-if="operation.official_state === 'PROSPECTION'"
                  label="Prospecção"
                  color="neutral"
                  variant="outline"
                  size="sm"
                />
              </span>
            </button>
            <p v-if="!filteredOperations.length" class="py-8 text-center text-xs text-muted">
              Nenhuma operação neste filtro.
            </p>
          </div>
        </aside>

        <section v-if="selected" class="min-w-0 space-y-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-semibold text-highlighted">
                  {{ selected.label }}
                </h3>
                <UBadge
                  :label="selectedAvailability.label"
                  :color="selectedAvailability.color"
                  :icon="selectedAvailability.icon"
                  variant="subtle"
                  size="sm"
                />
              </div>
              <p class="mt-1 text-xs text-muted">
                {{ selected.official_route }} · {{ selected.is_billable ? 'pode consumir franquia' : 'não bilhetável' }}
                · {{ selected.result_kind === 'DOCUMENT' ? 'retorno documental' : 'retorno estruturado' }}
              </p>
            </div>
          </div>

          <UAlert
            v-if="permissionDenied"
            color="warning"
            variant="subtle"
            icon="i-lucide-shield-off"
            :title="permissionDenied"
          />

          <UFormField label="Cliente" required>
            <USelect
              v-model="clientId"
              :items="clientOptions"
              value-key="value"
              label-key="label"
              placeholder="Selecione o contribuinte"
              :loading="clientsLoading"
              class="w-full"
              data-testid="declaration-operation-client"
            />
          </UFormField>

          <div v-if="selected.params.length" class="grid gap-3 sm:grid-cols-2">
            <UFormField
              v-for="field in selected.params"
              :key="field.name"
              :label="field.label"
              :required="field.required"
              :description="field.help"
              :class="requiresTextarea(field.type) ? 'sm:col-span-2' : ''"
            >
              <UTextarea
                v-if="requiresTextarea(field.type)"
                v-model="paramValues[field.name]"
                :rows="field.type === 'base64' ? 5 : 4"
                :placeholder="field.type === 'object' ? '{ }' : field.type === 'array' ? '[ ]' : 'Cole o conteúdo Base64'"
                class="w-full font-mono text-xs"
                :data-testid="`declaration-param-${field.name}`"
              />
              <UInput
                v-else
                v-model="paramValues[field.name]"
                :type="inputType(field.type)"
                :inputmode="field.type === 'integer' ? 'numeric' : undefined"
                :placeholder="field.format || field.type"
                class="w-full"
                :data-testid="`declaration-param-${field.name}`"
              />
            </UFormField>
          </div>
          <p v-else class="rounded-lg bg-elevated/50 p-3 text-xs text-muted">
            Esta ação não exige parâmetros adicionais.
          </p>

          <UAlert
            v-if="error"
            :color="preflight && !preflight.eligible ? 'warning' : 'error'"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :title="error"
            data-testid="declaration-operation-error"
          />

          <div
            v-if="preflight"
            class="space-y-3 rounded-xl border border-default bg-elevated/30 p-3"
            data-testid="declaration-operation-preflight"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="text-sm font-semibold">
                Preflight
              </p>
              <UBadge
                :label="preflight.eligible ? 'Elegível' : 'Bloqueada'"
                :color="preflight.eligible ? 'success' : 'warning'"
                variant="subtle"
              />
            </div>
            <p class="text-xs text-muted">
              {{ preflight.effect || preflight.denial_message || preflight.eligibility?.messages?.[0] }}
            </p>
            <p v-if="preflight.estimated_cost_micros != null" class="text-xs text-muted">
              Estimativa interna: {{ (preflight.estimated_cost_micros / 1_000_000).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) }}
            </p>

            <template v-if="preflight.eligible">
              <UFormField label="Reconfirme sua senha" required>
                <UInput
                  v-model="password"
                  type="password"
                  autocomplete="current-password"
                  class="w-full"
                  data-testid="declaration-operation-password"
                />
              </UFormField>
              <UFormField :label="`Digite: ${preflight.confirmation_phrase}`" required>
                <UInput
                  v-model="confirmationInput"
                  :placeholder="preflight.confirmation_phrase || ''"
                  class="w-full font-mono"
                  data-testid="declaration-operation-confirmation-phrase"
                />
              </UFormField>
              <UCheckbox
                v-model="explicitlyConfirmed"
                label="Li e confirmo a consequência fiscal desta operação."
                data-testid="declaration-operation-confirm"
              />
            </template>
          </div>

          <div
            v-if="mutation"
            class="rounded-xl border border-default bg-elevated/30 p-3"
            data-testid="declaration-operation-status"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="flex items-center gap-2">
                <UBadge
                  :label="mutationStatus.label"
                  :color="mutationStatus.color"
                  :icon="mutationStatus.icon"
                  variant="subtle"
                />
                <span v-if="polling" class="text-xs text-muted">
                  Atualizando…
                </span>
              </div>
              <span class="text-xs text-muted">
                Operação #{{ mutation.id }}
              </span>
            </div>
            <p v-if="mutation.result_message" class="mt-2 text-xs text-muted">
              {{ mutation.result_message }}
            </p>
            <p v-if="mutation.evidence_ref" class="mt-1 text-xs text-muted">
              Evidência: {{ mutation.evidence_ref }}
            </p>
          </div>

          <div
            v-if="readResult"
            class="rounded-xl border border-default bg-elevated/30 p-3"
            data-testid="declaration-operation-read-result"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="text-sm font-semibold">
                Consulta {{ readResult.async ? 'enfileirada' : 'registrada' }}
              </p>
              <UBadge label="Sem coleta implícita" color="success" variant="subtle" />
            </div>
            <pre v-if="readResultJson" class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded-lg bg-default p-2 text-xs">{{ readResultJson }}</pre>
          </div>
        </section>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-muted">
          {{ selected?.official_state === 'PROSPECTION' ? 'Catálogo visível; execução bloqueada pela fonte oficial.' : 'Nenhuma ação ocorre ao abrir este modal.' }}
        </p>
        <div class="flex items-center gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Fechar"
            :disabled="busy"
            @click="closeModal"
          />
          <UButton
            v-if="mutation?.allows_reconciliation"
            color="warning"
            variant="soft"
            icon="i-lucide-refresh-cw"
            label="Reconciliar"
            :loading="busy"
            @click="reconcileResult"
          />
          <UButton
            :color="selected?.flow === 'MUTATION' && preflight?.eligible ? 'error' : 'primary'"
            :icon="primaryIcon"
            :label="primaryLabel"
            :loading="busy"
            :disabled="primaryDisabled"
            data-testid="declaration-operation-submit"
            @click="submit"
          />
        </div>
      </div>
    </template>
  </ShellFormModal>
</template>
