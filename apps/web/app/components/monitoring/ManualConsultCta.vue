<script setup lang="ts">
/**
 * CTA de consulta manual reutilizável nas superfícies de módulo.
 * Usa o mesmo contrato do explorador (inventário filtrado + POST confirmado).
 */
import type { ManualConsultAction } from '~/types/fiscal-modules'

const props = withDefaults(defineProps<{
  clientId: number | null
  moduleKey?: string | null
  surfaceKey?: string | null
  /** Prefere esta action_id se estiver ready; senão a primeira ready do inventário. */
  preferredActionId?: string | null
  label?: string
  size?: 'xs' | 'sm' | 'md'
}>(), {
  moduleKey: null,
  surfaceKey: null,
  preferredActionId: null,
  label: 'Consulta manual',
  size: 'sm'
})

const emit = defineEmits<{
  executed: [payload: { action_id: string, client_id: number, module_route: string }]
  refresh: []
}>()

const { canTriggerSync } = useDashboard()
const { loading, executing, actions, loadInventory, execute } = useManualConsultExplorer()
const toast = useToast()

const confirmOpen = ref(false)
const selected = ref<ManualConsultAction | null>(null)
const paramValues = ref<Record<string, string>>({})

const preferred = computed(() => {
  if (props.preferredActionId) {
    return actions.value.find(a => a.action_id === props.preferredActionId) || null
  }
  return actions.value.find(a => a.executable) || actions.value[0] || null
})

const blockedReason = computed(() => {
  if (!props.clientId) return 'Selecione um cliente'
  if (!preferred.value) return 'Nenhuma ação disponível'
  if (!preferred.value.executable) return preferred.value.eligibility_label
  if (!canTriggerSync.value) return 'Sem permissão'
  return null
})

const canRun = computed(() => blockedReason.value === null)

function closeConfirm() {
  confirmOpen.value = false
}

async function ensureInventory() {
  if (!props.clientId) return
  await loadInventory({
    client_id: props.clientId,
    module_key: props.moduleKey || undefined,
    surface_key: props.surfaceKey || undefined
  })
}

async function openConfirm() {
  if (!props.clientId) {
    toast.add({ title: 'Informe o cliente.', color: 'warning' })
    return
  }
  await ensureInventory()
  if (!preferred.value?.executable) {
    toast.add({
      title: 'Consulta indisponível',
      description: preferred.value?.eligibility_label || 'Ação não ready',
      color: 'warning'
    })
    return
  }
  selected.value = preferred.value
  const defaults: Record<string, string> = {}
  for (const field of preferred.value.params_schema || []) {
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
      if (field.required) throw new Error(`Informe: ${field.label}`)
      continue
    }
    if (field.type === 'integer') out[field.name] = Number(raw)
    else if (field.type === 'object') out[field.name] = JSON.parse(String(raw))
    else out[field.name] = String(raw)
  }
  return out
}

async function confirmExecute() {
  if (!selected.value || !props.clientId) return
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
    client_id: props.clientId,
    params
  })
  if (result) {
    confirmOpen.value = false
    emit('executed', {
      action_id: result.action_id,
      client_id: props.clientId,
      module_route: result.module_route
    })
    emit('refresh')
  }
}

watch(
  () => [props.clientId, props.moduleKey, props.surfaceKey] as const,
  () => {
    if (props.clientId) void ensureInventory()
  },
  { immediate: true }
)
</script>

<template>
  <div data-testid="manual-consult-cta">
    <UButton
      :size="size"
      color="primary"
      variant="soft"
      icon="i-lucide-search-check"
      :label="label"
      :loading="loading || executing"
      :disabled="!canRun"
      :title="blockedReason || 'Confirmar consulta bilhetável'"
      data-testid="manual-consult-cta-button"
      @click="openConfirm"
    />

    <ShellFormModal
      v-model:open="confirmOpen"
      title="Confirmar consulta manual"
      description="Pode consumir franquia SERPRO. A tela recarrega a projeção local após sucesso."
      :show-default-footer="false"
      @cancel="closeConfirm"
    >
      <template #body>
        <div
          v-if="selected"
          class="space-y-3"
          data-testid="manual-consult-cta-confirm"
        >
          <p class="text-sm font-medium">
            {{ selected.label }}
          </p>
          <p class="text-xs text-muted">
            {{ selected.eligibility_label }}
          </p>
          <div
            v-for="field in selected.params_schema"
            :key="field.name"
          >
            <UFormField
              :label="field.label"
              :required="field.required"
            >
              <UInput
                v-model="paramValues[field.name]"
                class="w-full"
              />
            </UFormField>
          </div>
        </div>
      </template>
      <template #footer>
        <ShellModalFooter
          submit-label="Confirmar"
          :loading="executing"
          submit-test-id="manual-consult-cta-submit"
          @cancel="closeConfirm"
          @submit="confirmExecute"
        />
      </template>
    </ShellFormModal>
  </div>
</template>
