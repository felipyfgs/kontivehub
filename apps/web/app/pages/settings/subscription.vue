<script setup lang="ts">
/**
 * Assinatura do escritório ativo (15.3).
 */
import type { OfficeSubscription } from '~/types/api'

const api = useApi()
const { sessionEpoch } = useDashboard()

const loading = ref(false)
const loadError = ref<string | null>(null)
const subscription = ref<OfficeSubscription | null>(null)

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const data = (await api.office.subscription()).data
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    subscription.value = data
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    subscription.value = null
    loadError.value = apiErrorMessage(caught, 'Assinatura não encontrada para este escritório.')
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

watch(sessionEpoch, () => {
  subscription.value = null
  void load()
})
onMounted(load)
</script>

<template>
  <!-- Chrome: ShellSectionHeader (template settings). -->
  <div>
    <ShellSectionHeader
      title="Assinatura"
      description="Plano e limites do escritório."
      test-id="settings-subscription-header"
    >
      <UButton
        to="/conta/consumo"
        color="neutral"
        label="Ver consumo"
        class="w-fit lg:ms-auto"
      />
    </ShellSectionHeader>

    <ShellLoadError
      v-if="loadError"
      :title="loadError"
      test-id="subscription-load-error"
      @retry="load"
    />

    <div
      v-if="loading && !subscription"
      class="text-sm text-muted"
    >
      Carregando…
    </div>

    <UPageCard
      v-else-if="subscription"
      variant="subtle"
      :title="subscription.plan"
      :description="`Status: ${subscription.status}`"
    >
      <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-muted">
            Período atual
          </dt>
          <dd>
            {{ formatDateTime(subscription.current_period_starts_at) }}
            →
            {{ formatDateTime(subscription.current_period_ends_at) }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Trial até
          </dt>
          <dd>{{ formatDateTime(subscription.trial_ends_at) }}</dd>
        </div>
        <div>
          <dt class="text-muted">
            Cota API mensal
          </dt>
          <dd class="font-medium">
            {{ subscription.limits.monthly_api_quota ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Máx. clientes
          </dt>
          <dd class="font-medium">
            {{ subscription.limits.max_clients ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Máx. usuários
          </dt>
          <dd class="font-medium">
            {{ subscription.limits.max_users ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Mutações externas
          </dt>
          <dd class="font-medium">
            {{ subscription.allows_mutations ? 'Permitidas pelo plano' : 'Bloqueadas pelo plano' }}
          </dd>
        </div>
        <div>
          <dt class="text-muted">
            Chamadas externas
          </dt>
          <dd class="font-medium">
            {{ subscription.allows_external_calls ? 'Permitidas' : 'Bloqueadas' }}
          </dd>
        </div>
      </dl>
    </UPageCard>
  </div>
</template>
