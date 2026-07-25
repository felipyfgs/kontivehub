<script setup lang="ts">
/**
 * Rollout / canário / smoke status (PLATFORM_ADMIN).
 */
import type { SerproGlobalHealth, SerproRolloutState } from '~/types/api'

const api = useApi()
const { sessionEpoch } = useDashboard()

const loading = ref(false)
const loadError = ref<string | null>(null)
const approvalsError = ref<string | null>(null)
const rollout = ref<SerproRolloutState | null>(null)
const health = ref<SerproGlobalHealth | null>(null)
const pendingApprovals = ref<Array<Record<string, unknown>>>([])

const killSwitchActive = computed(() => Boolean(rollout.value?.kill_switch?.global?.active))
const smokeReady = computed(() => Boolean(
  rollout.value?.free_smoke_ok || rollout.value?.smoke_status === 'FREE_SMOKE_OK'
))

function approvalPolicyLabel(policy: unknown) {
  if (policy === 'OWNER_CONFIRMATION') return 'Confirmação do Proprietário'
  if (policy === 'DUAL_APPROVAL') return 'Proprietário + Office ADMIN'
  return String(policy || 'Política não informada')
}

function deriveFromHealth(h: SerproGlobalHealth): SerproRolloutState {
  return {
    smoke_status: h?.smoke_status || 'PENDING_OPS',
    kill_switch: h?.kill_switch,
    free_smoke_ok: h?.smoke_status === 'FREE_SMOKE_OK',
    notes: null
  }
}

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  approvalsError.value = null
  try {
    // API real: GET /serpro/health + GET /serpro/rollouts (lista de aprovações).
    // Não existe GET /serpro/rollout singular — evita 404 no console.
    const [healthRes, rolloutsRes] = await Promise.allSettled([
      api.platform.serpro.health(),
      api.platform.serpro.rollouts.list()
    ])
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return

    if (healthRes.status === 'fulfilled' && healthRes.value.data?.kill_switch) {
      health.value = healthRes.value.data
      rollout.value = deriveFromHealth(healthRes.value.data)
    } else {
      health.value = null
      rollout.value = null
      loadError.value = apiErrorMessage(
        healthRes.status === 'rejected' ? healthRes.reason : null,
        'Falha ao carregar health SERPRO.'
      )
    }

    if (rolloutsRes.status === 'fulfilled') {
      pendingApprovals.value = Array.isArray(rolloutsRes.value.data) ? rolloutsRes.value.data : []
    } else {
      pendingApprovals.value = []
      approvalsError.value = apiErrorMessage(
        rolloutsRes.reason,
        'Falha ao carregar aprovações de rollout.'
      )
    }
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

watch(sessionEpoch, () => {
  rollout.value = null
  void load()
})
onMounted(load)
</script>

<template>
  <div
    class="flex flex-col gap-4 sm:gap-6"
    data-testid="admin-serpro-rollout"
  >
    <UPageCard
      title="Rollout e smoke"
      variant="naked"
      orientation="horizontal"
    >
      <UButton
        class="w-fit lg:ms-auto"
        color="neutral"
        variant="outline"
        icon="i-lucide-refresh-cw"
        label="Atualizar estado"
        :loading="loading"
        @click="load"
      />
    </UPageCard>

    <UAlert
      v-if="loadError"
      color="error"
      icon="i-lucide-circle-x"
      :title="loadError"
      :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
    />

    <section>
      <UPageCard
        title="Estado operacional"
        variant="naked"
        class="mb-4"
      />

      <UPageCard
        v-if="loading && !rollout"
        variant="subtle"
        aria-busy="true"
        aria-label="Carregando estado operacional"
      >
        <div class="grid gap-3 sm:grid-cols-2">
          <USkeleton
            v-for="item in 4"
            :key="item"
            class="h-12 w-full"
          />
        </div>
      </UPageCard>

      <UPageCard
        v-else-if="rollout"
        variant="subtle"
      >
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-muted">
              Smoke
            </dt>
            <dd class="font-medium">
              <UBadge
                :color="smokeReady ? 'success' : 'warning'"
                variant="subtle"
              >
                {{ rollout?.smoke_status || '—' }}
              </UBadge>
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Kill switch
            </dt>
            <dd class="font-medium">
              <UBadge
                :color="killSwitchActive ? 'error' : 'success'"
                variant="subtle"
              >
                {{ killSwitchActive ? 'Ativo — transporte bloqueado' : 'Desligado' }}
              </UBadge>
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Canário faturável
            </dt>
            <dd class="font-medium">
              {{ rollout.canary_enabled === true
                ? 'Habilitado (requer aprovação)'
                : rollout.canary_enabled === false ? 'Desligado' : 'Não informado neste snapshot' }}
            </dd>
          </div>
        </dl>
        <template #footer>
          <UButton
            to="/admin/serpro"
            color="neutral"
            variant="outline"
            label="Abrir status"
            icon="i-lucide-heart-pulse"
          />
        </template>
      </UPageCard>
    </section>

    <section>
      <UPageCard
        title="Aprovações de rollout"
        variant="naked"
        class="mb-4"
      />

      <UPageCard
        v-if="loading"
        variant="subtle"
        aria-busy="true"
        aria-label="Carregando aprovações de rollout"
      >
        <USkeleton class="h-12 w-full" />
      </UPageCard>

      <UAlert
        v-else-if="approvalsError"
        color="error"
        icon="i-lucide-circle-x"
        :title="approvalsError"
        :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
      />

      <UPageCard
        v-else-if="pendingApprovals.length"
        variant="subtle"
        :ui="{ container: 'p-0 sm:p-0 gap-y-0' }"
      >
        <ul class="divide-y divide-default text-sm">
          <li
            v-for="(item, idx) in pendingApprovals.slice(0, 10)"
            :key="String(item.id ?? idx)"
            class="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
            data-testid="serpro-rollout-approval-row"
          >
            <span class="font-medium text-highlighted">
              {{ item.action || '—' }}
              <UBadge
                v-if="item.approval_policy"
                class="ms-1"
                size="sm"
                variant="subtle"
                :color="item.approval_policy === 'OWNER_CONFIRMATION' ? 'warning' : 'info'"
              >
                {{ approvalPolicyLabel(item.approval_policy) }}
              </UBadge>
              <span class="text-muted font-normal">
                · {{ item.status || '—' }}
              </span>
            </span>
            <span class="text-xs text-muted">
              #{{ item.id ?? '—' }}
            </span>
          </li>
        </ul>
      </UPageCard>

      <UEmpty
        v-else
        icon="i-lucide-shield-check"
        title="Nenhuma aprovação pendente"
      />
    </section>

    <section aria-label="Limites operacionais">
      <UPageCard
        title="Limites operacionais"
        variant="naked"
        class="mb-4"
      />

      <UPageCard variant="subtle">
        <ul class="list-disc space-y-2 ps-4 text-sm text-muted">
          <li>Código implantado não ativa o driver real.</li>
          <li>Canário exige Proprietário + Office ADMIN.</li>
          <li>Kill switch, status e limites continuam bloqueadores.</li>
        </ul>
      </UPageCard>
    </section>
  </div>
</template>
