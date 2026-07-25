<script setup lang="ts">
/**
 * Visão geral SERPRO: readiness + kill switch + contrato ativo.
 * Consumo / Liberação / Canário ficam em deep-links secundários.
 */
import type { SerproGlobalHealth, SerproKillSwitchStatus, SerproReadinessSnapshot } from '~/types/api'
import {
  buildKillSwitchOffBody,
  expectedOwnerConfirmationPhrase
} from '~/utils/serpro-owner-confirmation'

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const loading = ref(false)
const loadError = ref<string | null>(null)
const killError = ref<string | null>(null)
const health = ref<SerproGlobalHealth | null>(null)
const readiness = ref<SerproReadinessSnapshot | null>(null)
const kill = ref<SerproKillSwitchStatus | null>(null)
const environment = ref('TRIAL')
const killReason = ref('')
const killLoading = ref(false)
const ownerConfirmOpen = ref(false)
const killStateKnown = computed(() => typeof kill.value?.global?.active === 'boolean')

const envItems = [
  { label: 'Demonstração SERPRO', value: 'TRIAL' },
  { label: 'Produção', value: 'PRODUCTION' }
]

function deriveReadiness(h: SerproGlobalHealth | null): SerproReadinessSnapshot {
  if (!h) {
    return { overall: 'UNKNOWN', gates: [] }
  }
  const gates: SerproReadinessSnapshot['gates'] = []
  const ksActive = Boolean(h.kill_switch?.global?.active)
  gates.push({
    code: 'KILL_SWITCH',
    scope: 'global',
    status: ksActive ? 'FAIL' : 'PASS',
    message: ksActive
      ? `Kill switch ativo (${h.kill_switch?.global?.source || 'runtime'})`
      : 'Kill switch desligado'
  })
  gates.push({
    code: 'ACTIVE_CONTRACT',
    scope: 'global',
    status: h.active_contract ? 'PASS' : 'FAIL',
    message: h.active_contract
      ? `Contrato #${h.active_contract.id} · ${h.active_contract.status}`
      : 'Nenhum contrato ativo no ambiente'
  })
  if (h.active_contract?.credentials_exposed) {
    gates.push({
      code: 'CREDENTIALS_EXPOSED',
      scope: 'global',
      status: 'FAIL',
      message: 'Credencial marcada como exposta — rotação obrigatória'
    })
  }
  const breakerState = String(h.circuit_breaker?.state || 'CLOSED').toUpperCase()
  gates.push({
    code: 'CIRCUIT_BREAKER',
    scope: 'global',
    status: breakerState === 'OPEN' ? 'FAIL' : breakerState === 'HALF_OPEN' ? 'WARN' : 'PASS',
    message: `Breaker ${breakerState}`
  })
  gates.push({
    code: 'SMOKE',
    scope: 'global',
    status: h.smoke_status === 'FREE_SMOKE_OK' ? 'PASS' : 'WARN',
    message: `Smoke: ${h.smoke_status || 'PENDING'}`
  })

  const failed = gates.some(g => g.status === 'FAIL')
  const warned = gates.some(g => g.status === 'WARN')
  return {
    overall: failed ? 'BLOCKED' : warned ? 'DEGRADED' : 'READY',
    environment: h.environment,
    gates,
    evidence_kind: 'offline',
    evaluated_at: new Date().toISOString()
  }
}

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  const requestedEnvironment = environment.value
  loading.value = true
  loadError.value = null
  killError.value = null
  try {
    const [healthRes, killRes, readinessRes] = await Promise.allSettled([
      api.platform.serpro.health({ environment: requestedEnvironment }),
      api.platform.serpro.killSwitch.status(),
      api.platform.serpro.readiness({ environment: requestedEnvironment })
    ])
    if (
      seq !== loadSeq
      || epoch !== sessionEpoch.value
      || requestedEnvironment !== environment.value
    ) return

    if (healthRes.status === 'fulfilled') {
      health.value = healthRes.value.data || null
      if (!health.value) loadError.value = 'A saúde global SERPRO não retornou dados.'
    } else {
      health.value = null
      loadError.value = apiErrorMessage(healthRes.reason, 'Falha ao carregar saúde global SERPRO.')
    }

    if (killRes.status === 'fulfilled') {
      kill.value = killRes.value.data || null
      if (!kill.value) killError.value = 'O estado do kill switch não foi retornado.'
    } else if (health.value?.kill_switch) {
      kill.value = health.value.kill_switch
    } else {
      kill.value = null
      killError.value = apiErrorMessage(killRes.reason, 'Falha ao carregar o kill switch.')
    }

    if (readinessRes.status === 'fulfilled') {
      readiness.value = readinessRes.value.data || deriveReadiness(health.value)
    } else {
      readiness.value = deriveReadiness(health.value)
    }
  } finally {
    if (
      seq === loadSeq
      && epoch === sessionEpoch.value
      && requestedEnvironment === environment.value
    ) {
      loading.value = false
    }
  }
}

async function toggleKill(active: boolean) {
  if (!killStateKnown.value) {
    toast.add({ title: 'Atualize o estado do kill switch antes de alterar o controle.', color: 'warning' })
    return
  }
  if (!killReason.value.trim()) {
    toast.add({ title: 'Informe o motivo auditável do kill switch.', color: 'warning' })
    return
  }
  // Ligar: imediato fail-closed. Desligar: confirmação OWNER (modal).
  if (!active) {
    ownerConfirmOpen.value = true
    return
  }
  killLoading.value = true
  try {
    const res = await api.platform.serpro.killSwitch.set({
      active: true,
      reason: killReason.value.trim()
    })
    kill.value = res.data
    toast.add({
      title: 'Kill switch SERPRO global ligado.',
      color: 'warning'
    })
    await load()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha no kill switch SERPRO.'), color: 'error' })
  } finally {
    killLoading.value = false
  }
}

async function confirmKillOff(payload: {
  reason: string
  confirmation_phrase: string
  password: string
}) {
  killLoading.value = true
  try {
    const res = await api.platform.serpro.killSwitch.set(
      buildKillSwitchOffBody({
        reason: payload.reason || killReason.value,
        confirmationPhrase: payload.confirmation_phrase
      }) as {
        active: boolean
        reason: string
        confirmation_phrase?: string
        change_window_start?: string
        change_window_end?: string
      }
    )
    kill.value = res.data
    toast.add({
      title: res.executed === false
        ? (res.message || 'Confirmação registrada.')
        : 'Kill switch SERPRO desligado com confirmação do proprietário.',
      color: 'success'
    })
    killReason.value = ''
    await load()
  } catch (caught) {
    const code = (caught as { data?: { code?: string } })?.data?.code
    toast.add({
      title: code === 'password_confirmation_required'
        ? 'Senha expirada — reconfirme e tente novamente.'
        : apiErrorMessage(caught, 'Falha ao desligar kill switch SERPRO.'),
      color: 'error'
    })
  } finally {
    killLoading.value = false
  }
}

function gateColor(status?: string): 'success' | 'warning' | 'error' | 'neutral' {
  switch (String(status || '').toUpperCase()) {
    case 'PASS': return 'success'
    case 'WARN': return 'warning'
    case 'FAIL': return 'error'
    default: return 'neutral'
  }
}

function readinessLabel(status?: string): string {
  switch (String(status || '').toUpperCase()) {
    case 'READY': return 'Pronto'
    case 'BLOCKED': return 'Bloqueado'
    case 'DEGRADED': return 'Atenção'
    default: return 'Indeterminado'
  }
}

function gateLabel(code: string): string {
  return {
    KILL_SWITCH: 'Kill switch',
    ACTIVE_CONTRACT: 'Contrato ativo',
    CREDENTIALS_EXPOSED: 'Proteção das credenciais',
    CIRCUIT_BREAKER: 'Circuit breaker',
    SMOKE: 'Verificação técnica'
  }[code] || code
}

function resetOverviewState() {
  loadSeq++
  loading.value = false
  health.value = null
  readiness.value = null
  kill.value = null
  loadError.value = null
  killError.value = null
  killReason.value = ''
  ownerConfirmOpen.value = false
}

watch(environment, () => {
  resetOverviewState()
  void load()
})
watch(sessionEpoch, () => {
  resetOverviewState()
  void load()
})
onMounted(() => {
  void load()
})
</script>

<template>
  <div
    class="flex flex-col gap-6"
    data-testid="admin-serpro-operation"
  >
    <div
      data-testid="admin-serpro-readiness"
      class="space-y-6"
    >
      <UPageCard
        title="Visão geral"
        variant="naked"
        orientation="horizontal"
      >
        <div class="flex w-fit flex-wrap items-end gap-2 lg:ms-auto">
          <UFormField label="Ambiente">
            <USelect
              v-model="environment"
              :items="envItems"
              value-key="value"
              class="w-40"
              aria-label="Ambiente SERPRO"
            />
          </UFormField>
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-refresh-cw"
            label="Atualizar"
            :loading="loading"
            @click="load"
          />
        </div>
      </UPageCard>

      <nav
        class="flex flex-wrap gap-x-4 gap-y-1 text-sm"
        aria-label="Atalhos operacionais SERPRO"
        data-testid="admin-serpro-overview-secondary-links"
      >
        <ULink
          to="/admin/serpro/usage"
          class="text-muted hover:text-highlighted"
        >
          Consumo
        </ULink>
        <ULink
          to="/admin/serpro/rollout"
          class="text-muted hover:text-highlighted"
        >
          Liberação
        </ULink>
        <ULink
          to="/admin/serpro/dte-canary"
          class="text-muted hover:text-highlighted"
        >
          Canário DTE
        </ULink>
      </nav>

      <UAlert
        v-if="loadError"
        color="error"
        icon="i-lucide-circle-x"
        :title="loadError || 'Não foi possível atualizar a visão geral'"
      >
        <template #actions>
          <UButton
            label="Tentar novamente"
            color="error"
            variant="soft"
            size="sm"
            :loading="loading"
            @click="load"
          />
        </template>
      </UAlert>

      <div
        v-if="loading && !health"
        class="flex flex-col gap-6"
        role="status"
        aria-live="polite"
      >
        <span class="sr-only">Carregando visão geral SERPRO.</span>
        <USkeleton class="h-36 w-full rounded-lg" />
        <div class="grid gap-6 sm:grid-cols-2">
          <USkeleton class="h-48 w-full rounded-lg" />
          <USkeleton class="h-48 w-full rounded-lg" />
        </div>
        <USkeleton class="h-24 w-full rounded-lg" />
      </div>

      <div
        v-else-if="health || readiness || kill"
        class="flex flex-col gap-6"
      >
        <UPageCard
          variant="subtle"
          data-testid="admin-serpro-overview"
        >
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="text-sm text-muted">
                Ambiente {{ readiness?.environment || environment }}
              </p>
              <h2 class="mt-1 text-lg font-semibold text-highlighted">
                Prontidão operacional
              </h2>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <UBadge
                :color="gateColor(readiness?.overall === 'READY' ? 'PASS' : readiness?.overall === 'BLOCKED' ? 'FAIL' : 'WARN')"
                variant="subtle"
                size="lg"
                data-testid="admin-serpro-overall"
              >
                {{ readinessLabel(readiness?.overall) }}
              </UBadge>
            </div>
          </div>

          <USeparator class="my-4" />

          <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div>
              <dt class="text-muted">
                Evidência
              </dt>
              <dd class="mt-1 font-medium text-highlighted">
                {{ readiness?.evidence_kind === 'live' ? 'Remota' : 'Local' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Avaliado em
              </dt>
              <dd class="mt-1 font-medium text-highlighted">
                {{ readiness?.evaluated_at ? formatDateTime(readiness.evaluated_at) : 'Não informado' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Bloqueios
              </dt>
              <dd class="mt-1 font-medium text-highlighted">
                {{ readiness?.gates?.filter(gate => gate.status === 'FAIL').length || 0 }} encontrados
              </dd>
            </div>
          </dl>
        </UPageCard>

        <div class="grid gap-6 sm:grid-cols-2">
          <UPageCard
            variant="subtle"
            data-testid="admin-serpro-kill-switch"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="font-semibold text-highlighted">
                  Controle de emergência
                </h2>
                <p class="mt-1 text-sm text-muted">
                  Interrompe o tráfego SERPRO.
                </p>
              </div>
              <UBadge
                :color="killStateKnown ? (kill?.global?.active ? 'error' : 'success') : 'neutral'"
                variant="subtle"
              >
                {{ killStateKnown ? (kill?.global?.active ? 'Ativo' : 'Desligado') : 'Indisponível' }}
              </UBadge>
            </div>

            <p
              v-if="kill?.global?.source"
              class="mt-3 text-xs text-muted"
            >
              Origem do estado: {{ kill.global.source }}
            </p>

            <div
              v-if="killStateKnown"
              class="mt-4"
            >
              <UFormField label="Motivo para auditoria">
                <UInput
                  v-model="killReason"
                  class="w-full"
                  placeholder="Descreva o motivo"
                  autocomplete="off"
                  data-testid="serpro-kill-reason"
                />
              </UFormField>
              <UButton
                v-if="!kill?.global?.active"
                class="mt-4 w-full justify-center"
                color="error"
                variant="soft"
                label="Ativar kill switch"
                icon="i-lucide-octagon-x"
                :loading="killLoading"
                data-testid="serpro-kill-on"
                @click="toggleKill(true)"
              />
              <UButton
                v-else
                class="mt-4 w-full justify-center"
                color="neutral"
                variant="outline"
                label="Solicitar desligamento"
                icon="i-lucide-shield-check"
                :loading="killLoading"
                data-testid="serpro-kill-off"
                @click="toggleKill(false)"
              />
            </div>
            <p
              v-else
              class="mt-4 text-sm text-muted"
              role="status"
            >
              {{ killError || 'Atualize a página para consultar o estado deste controle.' }}
            </p>
            <SerproOwnerConfirmModal
              v-model:open="ownerConfirmOpen"
              action="KILL_SWITCH_OFF"
              :expected-phrase="expectedOwnerConfirmationPhrase('KILL_SWITCH_OFF')"
              title="Desligar kill switch global"
              @confirm="confirmKillOff"
            />
          </UPageCard>

          <UPageCard variant="subtle">
            <div class="flex items-center justify-between gap-3">
              <h2 class="font-semibold text-highlighted">
                Contrato ativo
              </h2>
              <UBadge
                v-if="health?.active_contract"
                :color="health.active_contract.credentials_exposed ? 'error' : 'success'"
                variant="subtle"
              >
                {{ health.active_contract.status }}
              </UBadge>
            </div>

            <template v-if="health?.active_contract">
              <dl class="mt-4 space-y-3 text-sm">
                <div>
                  <dt class="text-muted">
                    Contratante
                  </dt>
                  <dd class="mt-1 font-medium text-highlighted">
                    {{ health.active_contract.contractor_name || health.active_contract.contractor_cnpj_masked || 'Não informado' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Certificado válido até
                  </dt>
                  <dd class="mt-1 font-medium text-highlighted">
                    {{ formatDateTime(health.active_contract.cert_valid_to) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Consumer Key
                  </dt>
                  <dd class="mt-1 font-mono text-xs text-highlighted">
                    {{ health.active_contract.consumer_key_hint || 'Não informado' }}
                  </dd>
                </div>
              </dl>

              <UAlert
                v-if="health.active_contract.credentials_exposed"
                class="mt-4"
                color="error"
                variant="subtle"
                icon="i-lucide-shield-alert"
                title="Credencial exposta — rotação obrigatória"
              />
            </template>

            <div
              v-else-if="health"
              class="mt-4 flex items-center gap-3 text-sm text-muted"
              role="status"
            >
              <UIcon
                name="i-lucide-file-x-2"
                class="size-5"
                aria-hidden="true"
              />
              Nenhum contrato ativo neste ambiente.
            </div>
            <div
              v-else
              class="mt-4 flex items-center gap-3 text-sm text-muted"
              role="status"
            >
              <UIcon
                name="i-lucide-circle-help"
                class="size-5"
                aria-hidden="true"
              />
              Estado do contrato indisponível.
            </div>
          </UPageCard>
        </div>

        <UPageCard
          variant="subtle"
          title="Verificações"
        >
          <ul
            v-if="readiness?.gates?.length"
            class="divide-y divide-default"
          >
            <li
              v-for="gate in readiness.gates"
              :key="gate.code"
              class="flex items-start justify-between gap-4 py-3 text-sm first:pt-0 last:pb-0"
            >
              <div class="min-w-0">
                <p class="font-medium text-highlighted">
                  {{ gateLabel(gate.code) }}
                </p>
                <p class="mt-0.5 text-muted">
                  {{ gate.message || 'Sem detalhes adicionais.' }}
                </p>
              </div>
              <UBadge
                :color="gateColor(gate.status)"
                variant="subtle"
                class="shrink-0"
              >
                {{ gate.status }}
              </UBadge>
            </li>
          </ul>
          <div
            v-else
            class="flex items-center gap-3 py-2 text-sm text-muted"
            role="status"
          >
            <UIcon
              name="i-lucide-list-checks"
              class="size-5"
              aria-hidden="true"
            />
            Nenhuma verificação disponível para este ambiente.
          </div>
        </UPageCard>
      </div>

      <UAlert
        v-if="environment === 'TRIAL'"
        color="warning"
        variant="subtle"
        icon="i-lucide-flask-conical"
        title="Demonstração SERPRO — resultados e checks não constituem evidência fiscal nem confirmação de operação real na SERPRO."
      />
    </div>
  </div>
</template>
