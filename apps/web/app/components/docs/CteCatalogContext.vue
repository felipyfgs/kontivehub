<script setup lang="ts">
/**
 * Contexto CT-e no catálogo Documentos (`kind=CTE`).
 * Orientação autXML, metadados seguros e pendências — sem saúde de cursor
 * (permanece em Sincronizações) e sem material sensível.
 */
import type { CteOnboarding, CtePendingItem } from '~/types/api'

const emit = defineEmits<{
  pendingResolved: []
}>()

const api = useApi()
const toast = useToast()
const { canAccessAdministration, canManageClients, sessionEpoch } = useDashboard()

const onboarding = ref<CteOnboarding | null>(null)
const pending = ref<CtePendingItem[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const resolvingId = ref<number | null>(null)
const expanded = ref(true)

const checklist = computed(() => [
  {
    label: 'CNPJ do escritório cadastrado',
    done: Boolean(onboarding.value?.identity?.cnpj || onboarding.value?.office_cnpj),
    detail: onboarding.value?.office_cnpj || onboarding.value?.identity?.cnpj
      ? formatCnpj(onboarding.value.office_cnpj || onboarding.value.identity?.cnpj)
      : 'Cadastre a identidade fiscal do escritório.'
  },
  {
    label: 'Certificado A1 operacional',
    done: onboarding.value?.credential?.status === 'ACTIVE',
    detail: onboarding.value?.credential
      ? `${statusLabel(onboarding.value.credential.status)} · válido até ${formatDateTime(onboarding.value.credential.valid_to)}`
      : 'Nenhum certificado público ativo encontrado.'
  },
  {
    label: 'Canal autXML habilitado',
    done: Boolean(onboarding.value?.enabled),
    detail: onboarding.value?.enabled ? 'Habilitado nesta instância.' : 'Desabilitado por configuração.'
  }
])

const credential = computed(() => onboarding.value?.credential || null)

const credentialAlerts = computed(() => {
  const c = credential.value
  if (!c) return [] as string[]
  const alerts: string[] = []
  if (c.expires_alert_1) alerts.push('Vence em até 1 dia')
  else if (c.expires_alert_7) alerts.push('Vence em até 7 dias')
  else if (c.expires_alert_30) alerts.push('Vence em até 30 dias')
  return alerts
})

const needsAttention = computed(() => {
  if (!onboarding.value) return true
  return checklist.value.some(item => !item.done) || pending.value.length > 0
})

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  try {
    const [onboardingResponse, pendingResponse] = await Promise.all([
      api.cte.onboarding(),
      api.cte.pending()
    ])
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    onboarding.value = onboardingResponse.data
    pending.value = pendingResponse.data || []
    loadError.value = null
  } catch (caught) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    onboarding.value = null
    pending.value = []
    loadError.value = apiErrorMessage(caught, 'Não foi possível carregar o contexto CT-e.')
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

async function copyOfficeCnpj() {
  const cnpj = onboarding.value?.office_cnpj || onboarding.value?.identity?.cnpj
  if (!cnpj) return
  try {
    await navigator.clipboard.writeText(cnpj)
    toast.add({ title: 'CNPJ copiado', color: 'success' })
  } catch {
    toast.add({ title: 'Não foi possível copiar o CNPJ', color: 'warning' })
  }
}

async function resolvePending(item: CtePendingItem, resolution: 'RESOLVED' | 'DISMISSED') {
  if (!canManageClients.value || resolvingId.value) return
  resolvingId.value = item.id
  try {
    await api.quarantine.resolve(item.id, {
      resolution_status: resolution,
      resolution_code: resolution === 'RESOLVED' ? 'OPERATOR_REVIEW' : 'DISMISSED_NO_ACTION',
      resolution_notes: resolution === 'RESOLVED'
        ? 'Revisado no catálogo CT-e (sem automação de portal).'
        : 'Descartado no catálogo CT-e.'
    })
    toast.add({
      title: resolution === 'RESOLVED' ? 'Pendência resolvida' : 'Pendência descartada',
      color: 'success'
    })
    pending.value = pending.value.filter(p => p.id !== item.id)
    emit('pendingResolved')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível atualizar a pendência.'),
      color: 'error'
    })
  } finally {
    resolvingId.value = null
  }
}

function clearState() {
  onboarding.value = null
  pending.value = []
  loadError.value = null
  loading.value = false
}

watch(sessionEpoch, () => {
  clearState()
  void load()
})

onMounted(load)

defineExpose({ reload: load, clearState })
</script>

<template>
  <div
    class="space-y-3"
    data-testid="cte-catalog-context"
  >
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div class="flex min-w-0 items-center gap-2">
        <UIcon
          name="i-lucide-truck"
          class="size-4 shrink-0 text-muted"
        />
        <p class="text-sm font-medium text-highlighted">
          Contexto CT-e
        </p>
        <UBadge
          v-if="needsAttention && !loading"
          color="warning"
          variant="subtle"
          size="sm"
          label="Atenção"
        />
        <UBadge
          v-if="pending.length"
          color="neutral"
          variant="subtle"
          size="sm"
          :label="`${pending.length} pendência(s)`"
        />
      </div>
      <div class="flex items-center gap-1">
        <UButton
          to="/syncs"
          color="neutral"
          variant="ghost"
          size="xs"
          icon="i-lucide-refresh-cw"
          label="Saúde dos canais"
        />
        <UButton
          color="neutral"
          variant="ghost"
          size="xs"
          :icon="expanded ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'"
          :aria-expanded="expanded"
          :aria-label="expanded ? 'Recolher contexto CT-e' : 'Expandir contexto CT-e'"
          @click="() => { expanded = !expanded }"
        />
      </div>
    </div>

    <template v-if="expanded">
      <UAlert
        color="info"
        variant="subtle"
        icon="i-lucide-info"
        title="O autXML não é retroativo"
      />

      <UAlert
        v-if="loadError"
        color="error"
        icon="i-lucide-wifi-off"
        :title="loadError"
        :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
      />

      <div
        v-if="loading && !onboarding"
        class="space-y-2"
        role="status"
        aria-live="polite"
        aria-busy="true"
      >
        <USkeleton class="h-16 w-full" />
        <USkeleton class="h-20 w-full" />
        <span class="sr-only">Carregando contexto CT-e…</span>
      </div>

      <UEmpty
        v-else-if="!loading && !loadError && !onboarding"
        icon="i-lucide-truck"
        title="Contexto CT-e indisponível"
        description="A API não retornou dados do escritório. Tente novamente ou confira a sessão."
        :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
      />

      <template v-else-if="onboarding">
        <div class="grid gap-3 lg:grid-cols-2">
          <UPageCard
            title="CNPJ para autXML"
            description="Informe este CNPJ completo ao emissor antes de ele autorizar o CT-e."
            variant="subtle"
          >
            <div class="flex flex-wrap items-center gap-3">
              <code
                class="rounded-md bg-elevated px-3 py-2 text-sm text-highlighted"
                data-testid="cte-office-cnpj"
              >
                {{ onboarding.office_cnpj ? formatCnpj(onboarding.office_cnpj) : 'Não configurado' }}
              </code>
              <UButton
                v-if="onboarding.office_cnpj"
                color="neutral"
                variant="outline"
                size="sm"
                icon="i-lucide-copy"
                label="Copiar"
                aria-label="Copiar CNPJ do escritório para autXML"
                @click="copyOfficeCnpj"
              />
            </div>
          </UPageCard>

          <div data-testid="cte-a1-metadata">
            <UPageCard
              v-if="credential"
              title="Certificado A1 (metadados seguros)"
              description="Somente status e validade pública — nunca PFX, senha, PEM ou chave privada."
              variant="subtle"
            >
              <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                  <dt class="text-muted">
                    Status
                  </dt>
                  <dd class="font-medium text-highlighted">
                    {{ statusLabel(credential.status) }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Válido até
                  </dt>
                  <dd class="font-medium text-highlighted">
                    {{ formatDateTime(credential.valid_to) }}
                  </dd>
                </div>
              </dl>
              <div
                v-if="credentialAlerts.length"
                class="mt-2 flex flex-wrap gap-2"
              >
                <UBadge
                  v-for="alert in credentialAlerts"
                  :key="alert"
                  color="warning"
                  variant="subtle"
                >
                  {{ alert }}
                </UBadge>
              </div>
              <UButton
                v-if="canAccessAdministration"
                to="/conta/escritorio"
                color="neutral"
                variant="link"
                size="xs"
                icon="i-lucide-sliders-horizontal"
                label="Configurar escritório"
                class="mt-2 px-0"
              />
            </UPageCard>
            <UPageCard
              v-else
              title="Certificado A1"
              description="Nenhum certificado público ativo."
              variant="subtle"
            >
              <UButton
                v-if="canAccessAdministration"
                to="/conta/escritorio"
                color="neutral"
                variant="subtle"
                size="sm"
                icon="i-lucide-sliders-horizontal"
                label="Configurar escritório"
              />
            </UPageCard>
          </div>
        </div>

        <UPageCard
          title="Checklist operacional"
          variant="subtle"
        >
          <ul
            class="divide-y divide-default"
            aria-label="Checklist operacional CT-e"
          >
            <li
              v-for="item in checklist"
              :key="item.label"
              class="flex items-start gap-3 py-2 first:pt-0 last:pb-0"
            >
              <UIcon
                :name="item.done ? 'i-lucide-circle-check' : 'i-lucide-circle-alert'"
                :class="item.done ? 'text-success' : 'text-warning'"
                class="mt-0.5 size-4 shrink-0"
                :aria-label="item.done ? 'Concluído' : 'Pendente'"
              />
              <div>
                <p class="text-sm font-medium text-highlighted">
                  {{ item.label }}
                </p>
                <p class="text-xs text-muted">
                  {{ item.detail }}
                </p>
              </div>
            </li>
          </ul>
        </UPageCard>

        <div data-testid="cte-pending-panel">
          <UPageCard
            title="Pendências e quarentena CT-e"
            description="Itens abertos do modelo 57. Resolução manual apenas — sem automação de portais."
            variant="subtle"
          >
            <div
              v-if="loading && !pending.length"
              class="flex items-center gap-2 text-sm text-muted"
              role="status"
            >
              <UIcon
                name="i-lucide-loader-circle"
                class="animate-spin"
              />
              Carregando pendências…
            </div>
            <UEmpty
              v-else-if="!pending.length"
              icon="i-lucide-inbox"
              title="Nenhuma pendência CT-e aberta"
              description="Quarentenas resolvidas ou sem itens em aberto para este escritório."
            />
            <ul
              v-else
              class="divide-y divide-default"
            >
              <li
                v-for="item in pending"
                :key="item.id"
                class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
                data-testid="cte-pending-item"
              >
                <div class="min-w-0 space-y-1">
                  <p class="font-medium text-highlighted">
                    {{ item.reason_label || item.reason }}
                  </p>
                  <p class="text-sm text-muted">
                    {{ item.source }}
                    <template v-if="item.channel">
                      · {{ item.channel }}
                    </template>
                    <template v-if="item.model">
                      · modelo {{ item.model }}
                    </template>
                  </p>
                  <p
                    v-if="item.access_key"
                    class="truncate font-mono text-xs text-dimmed"
                    :title="item.access_key"
                  >
                    {{ item.access_key.slice(0, 14) }}…
                  </p>
                  <p
                    v-if="item.issuer_cnpj"
                    class="font-mono text-xs text-muted"
                  >
                    Emitente {{ formatCnpj(item.issuer_cnpj) }}
                  </p>
                </div>
                <div
                  v-if="canManageClients"
                  class="flex shrink-0 flex-wrap gap-2"
                >
                  <UButton
                    size="sm"
                    color="primary"
                    variant="subtle"
                    label="Marcar resolvido"
                    :loading="resolvingId === item.id"
                    :aria-label="`Marcar pendência ${item.id} como resolvida`"
                    @click="resolvePending(item, 'RESOLVED')"
                  />
                  <UButton
                    size="sm"
                    color="neutral"
                    variant="ghost"
                    label="Descartar"
                    :disabled="resolvingId === item.id"
                    :aria-label="`Descartar pendência ${item.id}`"
                    @click="resolvePending(item, 'DISMISSED')"
                  />
                </div>
                <UBadge
                  v-else
                  color="neutral"
                  variant="subtle"
                  size="sm"
                >
                  Somente leitura
                </UBadge>
              </li>
            </ul>
            <p class="mt-3 text-xs text-muted">
              VIEWER só consulta. ADMIN e OPERATOR resolvem na quarentena; ações de cofre (A1/token) exigem ADMIN com 2FA em Administração.
            </p>
          </UPageCard>
        </div>

        <UAlert
          color="neutral"
          variant="subtle"
          icon="i-lucide-upload"
          title="Use importação para documentos anteriores"
        />
      </template>
    </template>
  </div>
</template>
