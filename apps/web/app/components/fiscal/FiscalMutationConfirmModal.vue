<script setup lang="ts">
/**
 * Confirmação reforçada de mutação fiscal (15.10):
 * senha recente → preflight → consequência → custo → frase de confirmação.
 */
import type { FiscalMutationPreflight } from '~/types/api'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  /** Payload base enviado a preflight/execute. */
  request: {
    client_id: number
    operation_key: string
    solution_code: string
    service_code: string
    operation_code: string
    competence_period_key?: string | null
    module?: string | null
    payload?: Record<string, unknown>
  } | null
  /** Contexto legível para o operador. */
  context?: {
    clientLabel?: string
    competence?: string
    effect?: string
  }
}>()

const emit = defineEmits<{
  success: [operationId: number]
}>()

const api = useApi()
const toast = useToast()
const { canExecuteHighRiskMutation } = useDashboard()

const step = ref<'idle' | 'preflight' | 'confirm' | 'submitting'>('idle')
const preflight = ref<FiscalMutationPreflight | null>(null)
const preflightError = ref<string | null>(null)
const password = ref('')
const confirmationPhrase = ref('')
const confirmed = ref(false)
const loading = ref(false)

const eligible = computed(() => preflight.value?.eligible === true)
const requiredPhrase = computed(() =>
  String(preflight.value?.confirmation_phrase || props.context?.effect || 'CONFIRMAR')
)
const costLabel = computed(() => {
  const p = preflight.value
  if (!p) return null
  if (typeof p.cost_estimate === 'string') return p.cost_estimate
  if (p.cost_estimate && typeof p.cost_estimate === 'object') {
    const o = p.cost_estimate as Record<string, unknown>
    if (o.label) return String(o.label)
    if (o.quantity != null) return `${o.quantity} unidade(s) do plano`
  }
  if (p.estimated_cost_micros != null) {
    return `Estimativa interna: ${p.estimated_cost_micros} µ (não é fatura)`
  }
  return 'Custo estimado não informado pela API'
})

watch(open, async (isOpen) => {
  if (!isOpen) {
    reset()
    return
  }
  if (!props.request) {
    preflightError.value = 'Pedido de mutação incompleto.'
    return
  }
  if (!canExecuteHighRiskMutation.value) {
    preflightError.value = 'Sem permissão para executar mutações fiscais.'
    return
  }
})

function reset() {
  step.value = 'idle'
  preflight.value = null
  preflightError.value = null
  password.value = ''
  confirmationPhrase.value = ''
  confirmed.value = false
  loading.value = false
}

async function runPreflight() {
  if (!props.request) return
  if (!password.value) {
    preflightError.value = 'Confirme sua senha para continuar.'
    return
  }
  loading.value = true
  step.value = 'preflight'
  preflightError.value = null
  try {
    await api.confirmPassword(password.value)
    password.value = ''
    const res = await api.fiscal.mutations.preflight({
      ...props.request,
      payload: props.request.payload || {}
    })
    preflight.value = res.data
    step.value = 'confirm'
  } catch (caught) {
    preflightError.value = apiErrorMessage(
      caught,
      'Preflight recusado. A coorte pode estar somente leitura ou sem elegibilidade.'
    )
    // Tenta extrair data.partial de 422
    const data = (caught as { data?: { data?: FiscalMutationPreflight } })?.data?.data
    if (data) preflight.value = data
    step.value = 'confirm'
  } finally {
    loading.value = false
  }
}

async function submit() {
  if (!props.request || !preflight.value) return
  if (!confirmed.value) {
    toast.add({ title: 'Marque a confirmação explícita da consequência.', color: 'warning' })
    return
  }
  if (confirmationPhrase.value.trim() !== requiredPhrase.value) {
    toast.add({ title: 'Frase de confirmação incorreta.', color: 'warning' })
    return
  }
  loading.value = true
  step.value = 'submitting'
  try {
    const res = await api.fiscal.mutations.execute({
      ...props.request,
      payload: props.request.payload || {},
      preflight_token: preflight.value.preflight_token,
      confirmation_phrase: confirmationPhrase.value.trim(),
      confirmed: true
    })
    toast.add({ title: 'Operação registrada', color: 'success' })
    open.value = false
    emit('success', res.data.id)
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao executar mutação fiscal.'),
      color: 'error'
    })
    step.value = 'confirm'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    title="Confirmar operação fiscal"
    description="Senha recente, preflight, consequência e custo estimado são obrigatórios."
    :ui="{ content: 'sm:max-w-lg' }"
  >
    <template #body>
      <div class="space-y-4 text-sm">
        <UAlert
          v-if="preflightError"
          color="error"
          icon="i-lucide-circle-x"
          :title="preflightError"
        />

        <UAlert
          v-if="preflight && !eligible"
          color="warning"
          icon="i-lucide-shield-off"
          :title="preflight.denial_message || 'Operação não elegível'"
        />

        <dl class="grid gap-2 rounded-lg bg-elevated/50 p-3">
          <div class="flex justify-between gap-3">
            <dt class="text-muted">
              Contribuinte
            </dt>
            <dd class="text-right font-medium">
              {{ context?.clientLabel || `Cliente #${request?.client_id || '—'}` }}
            </dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-muted">
              Competência
            </dt>
            <dd class="text-right">
              {{ context?.competence || request?.competence_period_key || '—' }}
            </dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-muted">
              Operação
            </dt>
            <dd class="text-right font-mono text-xs">
              {{ request?.solution_code }}/{{ request?.service_code }}/{{ request?.operation_code }}
            </dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-muted">
              Efeito
            </dt>
            <dd class="text-right">
              {{ preflight?.effect_summary || context?.effect || '—' }}
            </dd>
          </div>
          <div class="flex justify-between gap-3">
            <dt class="text-muted">
              Custo estimado
            </dt>
            <dd class="text-right">
              {{ costLabel || '—' }}
            </dd>
          </div>
          <div
            v-if="preflight?.eligibility"
            class="flex justify-between gap-3"
          >
            <dt class="text-muted">
              Procuração / elegibilidade
            </dt>
            <dd class="max-w-[60%] text-right text-xs">
              {{ JSON.stringify(preflight.eligibility) }}
            </dd>
          </div>
        </dl>

        <UFormField
          v-if="!preflight || !eligible"
          label="Sua senha"
          name="password"
          required
          description="Reconfirmação para a operação sensível."
        >
          <UInput
            v-model="password"
            type="password"
            autocomplete="current-password"
            :disabled="loading"
          />
        </UFormField>

        <template v-if="eligible">
          <UFormField
            :label="`Digite a frase: ${requiredPhrase}`"
            name="phrase"
            required
          >
            <UInput
              v-model="confirmationPhrase"
              :placeholder="requiredPhrase"
              :disabled="loading"
            />
          </UFormField>

          <UCheckbox
            v-model="confirmed"
            label="Li e confirmo a consequência fiscal desta operação."
            :disabled="loading"
          />
        </template>

        <p
          v-else-if="!loading && !preflightError"
          class="text-muted"
        >
          Mutações estão desabilitadas ou o preflight ainda não retornou elegibilidade.
        </p>
      </div>
    </template>

    <template #footer>
      <ShellModalFooter>
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancelar"
          :disabled="loading"
          @click="() => { open = false }"
        />
        <UButton
          v-if="eligible"
          color="error"
          label="Executar mutação"
          :loading="loading"
          @click="submit"
        />
        <UButton
          v-else
          color="neutral"
          variant="soft"
          :label="preflight ? 'Tentar preflight de novo' : 'Confirmar senha e analisar'"
          :loading="loading"
          @click="runPreflight"
        />
      </ShellModalFooter>
    </template>
  </UModal>
</template>
