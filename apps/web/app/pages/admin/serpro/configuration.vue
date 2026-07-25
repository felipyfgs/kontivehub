<script setup lang="ts">
/**
 * Configuração SERPRO: credenciais, onboarding PRODUÇÃO e limites.
 * Contratos / cobertura ficam em deep-links secundários.
 * Sem preenchimento de segredo em re-leitura; sem download de vault.
 */
import type {
  SerproCredentialVersionSanitized,
  SerproPlatformConfiguration,
  SerproProductionOnboardingEnvelope,
  SerproProductionOnboardingState
} from '~/types/api'
import {
  defaultChangeWindow,
  expectedOwnerConfirmationPhrase
} from '~/utils/serpro-owner-confirmation'

const api = useApi()
const toast = useToast()
const { sessionEpoch } = useDashboard()

const loading = ref(false)
const acting = ref(false)
const loadError = ref<string | null>(null)
const configuration = ref<SerproPlatformConfiguration | null>(null)
const productionOnboarding = ref<SerproProductionOnboardingEnvelope | null>(null)
const onboardingLoadError = ref<string | null>(null)
const environment = ref<'TRIAL' | 'PRODUCTION'>('TRIAL')
const passwordModalOpen = ref(false)
const passwordInput = ref('')
const pendingAction = ref<null | (() => Promise<void>)>(null)

/** Cutover exige confirmação OWNER (frase + senha) antes do POST /cutover. */
const cutoverOwnerOpen = ref(false)
const cutoverTarget = ref<SerproCredentialVersionSanitized | null>(null)
const cutoverPhrase = expectedOwnerConfirmationPhrase('CREDENTIAL_CUTOVER')

const envItems = [
  { label: 'Demonstração SERPRO', value: 'TRIAL' },
  { label: 'Produção', value: 'PRODUCTION' }
]

// Upload form — segredos só no envio; nunca re-hidratados da API.
const upload = reactive({
  pfx: null as File | null,
  password: '',
  consumer_key: '',
  consumer_secret: '',
  notes: '',
  consent_granted: false
})

const limitsForm = reactive({
  cycle_start_day: 1,
  alert_percent: 80,
  global_limit_quantity: null as number | null
})

function clearUpload() {
  upload.pfx = null
  upload.password = ''
  upload.consumer_key = ''
  upload.consumer_secret = ''
  upload.notes = ''
  upload.consent_granted = false
}

function resetLimits() {
  limitsForm.cycle_start_day = 1
  limitsForm.alert_percent = 80
  limitsForm.global_limit_quantity = null
}

let loadSeq = 0

async function load() {
  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  const requestedEnvironment = environment.value
  loading.value = true
  loadError.value = null
  onboardingLoadError.value = null
  try {
    const [res, onboardingRes] = await Promise.all([
      api.platform.serpro.configuration.show({ environment: requestedEnvironment }),
      requestedEnvironment === 'PRODUCTION'
        ? api.platform.serpro.productionOnboarding.show().catch((caught) => {
            onboardingLoadError.value = apiErrorMessage(caught, 'Falha ao carregar onboarding de produção.')
            return null
          })
        : Promise.resolve(null)
    ])
    if (
      seq !== loadSeq
      || epoch !== sessionEpoch.value
      || requestedEnvironment !== environment.value
    ) return
    configuration.value = res.data || null
    productionOnboarding.value = onboardingRes?.data || null
    const cfg = res.data?.usage_limits?.config as Record<string, unknown> | undefined
    if (cfg) {
      limitsForm.cycle_start_day = Number(cfg.cycle_start_day ?? 1)
      limitsForm.alert_percent = Number(cfg.alert_percent ?? 80)
      limitsForm.global_limit_quantity = cfg.global_limit_quantity != null
        ? Number(cfg.global_limit_quantity)
        : null
    }
  } catch (caught) {
    if (
      seq !== loadSeq
      || epoch !== sessionEpoch.value
      || requestedEnvironment !== environment.value
    ) return
    configuration.value = null
    productionOnboarding.value = null
    loadError.value = apiErrorMessage(caught, 'Falha ao carregar configuração SERPRO.')
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

function requestPasswordThen(fn: () => Promise<void>) {
  pendingAction.value = fn
  passwordInput.value = ''
  passwordModalOpen.value = true
}

async function submitPassword() {
  if (!passwordInput.value.trim()) {
    toast.add({ title: 'Informe a senha da sessão.', color: 'warning' })
    return
  }
  acting.value = true
  try {
    await api.confirmPassword(passwordInput.value.trim())
    passwordModalOpen.value = false
    const action = pendingAction.value
    pendingAction.value = null
    passwordInput.value = ''
    if (action) await action()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Senha inválida ou confirmação expirada.'),
      color: 'error'
    })
  } finally {
    acting.value = false
  }
}

function submitUpload() {
  if (!upload.pfx || !upload.password || !upload.consumer_key || !upload.consumer_secret) {
    toast.add({ title: 'Preencha PFX, senha, Key e Secret.', color: 'warning' })
    return
  }

  if (environment.value === 'PRODUCTION') {
    submitProductionOnboarding()
    return
  }

  requestPasswordThen(async () => {
    try {
      const body = new FormData()
      body.append('environment', environment.value)
      body.append('pfx', upload.pfx!)
      body.append('password', upload.password)
      body.append('consumer_key', upload.consumer_key)
      body.append('consumer_secret', upload.consumer_secret)
      if (upload.notes) body.append('notes', upload.notes)
      const created = await api.platform.serpro.credentialVersions.store(body)
      clearUpload()

      await activateTrialCredential(created.data)
      toast.add({ title: 'Credenciais salvas e ativadas na Demonstração SERPRO.', color: 'success' })
      await load()
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao salvar e ativar as credenciais.'), color: 'error' })
    }
  })
}

function submitProductionOnboarding() {
  if (!upload.consent_granted) {
    toast.add({ title: 'Confirme o consentimento para ativar a produção.', color: 'warning' })
    return
  }
  if (productionOnboarding.value && productionOnboarding.value.enabled === false) {
    toast.add({ title: 'Onboarding produtivo desabilitado por feature flag.', color: 'warning' })
    return
  }

  requestPasswordThen(async () => {
    try {
      const body = new FormData()
      body.append('consumer_key', upload.consumer_key)
      body.append('consumer_secret', upload.consumer_secret)
      body.append('certificate', upload.pfx!)
      body.append('certificate_password', upload.password)
      body.append('consent_granted', '1')

      const res = await api.platform.serpro.productionOnboarding.submit(
        body,
        makeOnboardingIdempotencyKey()
      )
      productionOnboarding.value = res.data || null
      clearUpload()
      toast.add({ title: productionOnboardingToastTitle(res.data?.onboarding), color: 'success' })
      await load()
    } catch (caught) {
      clearUpload()
      toast.add({ title: apiErrorMessage(caught, 'Falha ao ativar Produção SERPRO.'), color: 'error' })
    }
  })
}

/** Demonstração: um único submit cadastra, verifica, testa o OAuth e ativa a versão. */
async function activateTrialCredential(v: SerproCredentialVersionSanitized) {
  await api.platform.serpro.credentialVersions.verify(v.id)
  await api.platform.serpro.credentialVersions.testConnection(v.id)
  await executeCredentialCutover(
    v,
    'Ativação simplificada de credencial na Demonstração SERPRO.',
    cutoverPhrase
  )
}

function verifyVersion(v: SerproCredentialVersionSanitized) {
  requestPasswordThen(async () => {
    try {
      await api.platform.serpro.credentialVersions.verify(v.id)
      toast.add({ title: `Versão v${v.version_number} verificada.`, color: 'success' })
      await load()
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha na verificação.'), color: 'error' })
    }
  })
}

function testConnection(v: SerproCredentialVersionSanitized) {
  requestPasswordThen(async () => {
    try {
      await api.platform.serpro.credentialVersions.testConnection(v.id)
      toast.add({ title: 'Teste OAuth mTLS OK (evidência registrada).', color: 'success' })
      await load()
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Teste OAuth falhou.'), color: 'error' })
    }
  })
}

function cutover(v: SerproCredentialVersionSanitized) {
  if (!v.has_recent_connection_test) {
    toast.add({
      title: 'Execute “Testar OAuth” de novo (evidência expira ~15 min) antes do cutover.',
      color: 'warning'
    })
    return
  }
  cutoverTarget.value = v
  cutoverOwnerOpen.value = true
}

/**
 * Fluxo canônico: request rollout CREDENTIAL_CUTOVER → approve OWNER → cutover(approval_id).
 * Sem contrato prévio o backend cria shell a partir da versão.
 */
async function confirmCutover(payload: {
  reason: string
  confirmation_phrase: string
  password: string
}) {
  const v = cutoverTarget.value
  if (!v) return

  acting.value = true
  try {
    await executeCredentialCutover(v, payload.reason, payload.confirmation_phrase)

    toast.add({ title: `Cutover v${v.version_number} concluído.`, color: 'success' })
    cutoverTarget.value = null
    await load()
  } catch (caught) {
    const msg = apiErrorMessage(caught, 'Cutover bloqueado.')
    toast.add({
      title: /oauth|evidência|teste/i.test(msg)
        ? `${msg} — rode “Testar OAuth” e tente de novo.`
        : msg,
      color: 'error'
    })
  } finally {
    acting.value = false
  }
}

async function executeCredentialCutover(
  v: SerproCredentialVersionSanitized,
  reason: string,
  confirmationPhrase: string
) {
  const window = defaultChangeWindow()
  const env = String(v.environment || environment.value || 'TRIAL').toUpperCase()
  const requested = await api.platform.serpro.rollouts.request({
    action: 'CREDENTIAL_CUTOVER',
    subject_type: 'CREDENTIAL_VERSION',
    subject_id: v.id,
    reason,
    environment: env,
    change_window_start: window.change_window_start,
    change_window_end: window.change_window_end
  })

  const approvalId = Number(
    (requested.data as { id?: number | string } | undefined)?.id
  )
  if (!Number.isFinite(approvalId) || approvalId <= 0) {
    throw new Error('Aprovação OWNER não retornou id utilizável.')
  }

  await api.platform.serpro.rollouts.approve(approvalId, {
    reason,
    confirmation_phrase: confirmationPhrase,
    change_window_start: window.change_window_start,
    change_window_end: window.change_window_end
  })

  await api.platform.serpro.credentialVersions.cutover(v.id, {
    approval_id: approvalId,
    reason
  })
}

function saveLimits() {
  requestPasswordThen(async () => {
    try {
      await api.platform.serpro.usageLimits.update({
        environment: environment.value,
        cycle_start_day: limitsForm.cycle_start_day,
        alert_percent: limitsForm.alert_percent,
        global_limit_quantity: limitsForm.global_limit_quantity ?? undefined
      })
      toast.add({ title: 'Limites quantitativos salvos.', color: 'success' })
      await load()
    } catch (caught) {
      toast.add({ title: apiErrorMessage(caught, 'Falha ao salvar limites.'), color: 'error' })
    }
  })
}

const summary = computed(() => configuration.value?.summary)
const pendingVersions = computed(() => configuration.value?.pending_credential_versions || [])
const productionState = computed(() => productionOnboarding.value?.onboarding || null)
const productionConsent = computed(() => productionOnboarding.value?.consent || null)
function makeOnboardingIdempotencyKey(): string {
  const bytes = new Uint32Array(2)
  if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
    crypto.getRandomValues(bytes)
  } else {
    bytes[0] = Math.floor(Math.random() * 0xffffffff)
    bytes[1] = Math.floor(Math.random() * 0xffffffff)
  }

  const first = bytes[0] ?? 0
  const second = bytes[1] ?? 0

  return `serpro-prod-${Date.now()}-${first.toString(16)}${second.toString(16)}`
}

function productionOnboardingToastTitle(state?: SerproProductionOnboardingState | null): string {
  if (state?.status === 'ACTIVE_SYNC_PENDING') return 'Produção ativada; primeira leitura da Caixa Postal foi enfileirada.'
  if (state?.status === 'ACTION_REQUIRED') return 'Produção ativada parcialmente; há ação pendente antes da primeira leitura.'
  if (state?.status === 'ACTIVE') return 'Produção SERPRO ativada.'
  return 'Onboarding de produção registrado.'
}

function productionStatusLabel(status?: string | null): string {
  return {
    PENDING: 'Pendente',
    RUNNING: 'Processando',
    ACTIVE_SYNC_PENDING: 'Ativa; sync pendente',
    ACTIVE: 'Ativa',
    ACTION_REQUIRED: 'Ação necessária',
    FAILED: 'Falhou'
  }[status || ''] || status || 'Não iniciado'
}

function productionStatusColor(status?: string | null): 'success' | 'warning' | 'error' | 'neutral' {
  if (status === 'ACTIVE' || status === 'ACTIVE_SYNC_PENDING') return 'success'
  if (status === 'ACTION_REQUIRED' || status === 'RUNNING' || status === 'PENDING') return 'warning'
  if (status === 'FAILED') return 'error'
  return 'neutral'
}

function credentialStatusLabel(status: string): string {
  return {
    PENDING: 'Pendente',
    VERIFIED: 'Verificada',
    ACTIVE: 'Ativa',
    RETIRED: 'Substituída',
    COMPROMISED: 'Comprometida'
  }[status] || status
}

function credentialStatusColor(status: string): 'success' | 'warning' | 'error' | 'neutral' {
  if (status === 'ACTIVE') return 'success'
  if (status === 'PENDING' || status === 'VERIFIED') return 'warning'
  if (status === 'COMPROMISED') return 'error'
  return 'neutral'
}

function cancelPasswordConfirmation() {
  passwordModalOpen.value = false
  passwordInput.value = ''
  pendingAction.value = null
}

function resetEnvironmentState() {
  loadSeq++
  loading.value = false
  configuration.value = null
  productionOnboarding.value = null
  loadError.value = null
  onboardingLoadError.value = null
  clearUpload()
  resetLimits()
  cancelPasswordConfirmation()
}

watch(environment, () => {
  resetEnvironmentState()
  void load()
})
watch(sessionEpoch, () => {
  resetEnvironmentState()
  void load()
})
onMounted(() => {
  void load()
})
</script>

<template>
  <div class="flex flex-col gap-6" data-testid="admin-serpro-integration">
    <div
      data-testid="admin-serpro-configuration"
      class="space-y-8"
    >
      <nav
        class="flex flex-wrap gap-x-4 gap-y-1 text-sm"
        aria-label="Atalhos de configuração SERPRO"
        data-testid="admin-serpro-config-secondary-links"
      >
        <ULink
          to="/admin/serpro/contracts"
          class="text-muted hover:text-highlighted"
        >
          Contratos
        </ULink>
        <ULink
          to="/admin/serpro/catalog"
          class="text-muted hover:text-highlighted"
        >
          Cobertura
        </ULink>
      </nav>
      <UPageCard
        title="Configuração por ambiente"
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
              aria-label="Ambiente da configuração SERPRO"
              data-testid="serpro-config-env"
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

      <UAlert
        v-if="loadError"
        color="error"
        icon="i-lucide-circle-x"
        :title="loadError || 'Não foi possível carregar a configuração'"
        data-testid="serpro-config-error"
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

      <UAlert
        v-if="onboardingLoadError"
        color="warning"
        icon="i-lucide-triangle-alert"
        :title="onboardingLoadError"
        data-testid="serpro-prod-onboarding-load-error"
      />

      <div
        v-if="loading && !configuration"
        class="space-y-4"
        role="status"
        aria-live="polite"
        data-testid="serpro-config-loading"
      >
        <span class="sr-only">Carregando configuração SERPRO.</span>
        <USkeleton class="h-32 w-full rounded-lg" />
        <USkeleton class="h-52 w-full rounded-lg" />
      </div>

      <template v-if="configuration">
        <UPageCard
          variant="subtle"
          data-testid="serpro-config-summary"
        >
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <p class="text-sm text-muted">
                {{ environment === 'PRODUCTION' ? 'Produção' : 'Demonstração SERPRO' }}
              </p>
              <h2 class="mt-1 text-lg font-semibold text-highlighted">
                Estado da configuração
              </h2>
            </div>
            <UBadge
              :color="summary?.configuration_ready ? 'success' : 'warning'"
              variant="subtle"
              size="lg"
            >
              {{ summary?.configuration_ready
                ? (environment === 'PRODUCTION' ? 'Pronta para operar' : 'Demonstração configurada')
                : 'Requer configuração' }}
            </UBadge>
          </div>

          <USeparator class="my-4" />

          <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <dt class="text-muted">
                Credencial
              </dt>
              <dd class="mt-1 font-medium text-highlighted">
                {{ summary ? (summary.has_active_credential ? 'Ativa' : 'Não ativada') : 'Indisponível' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Kill switch
              </dt>
              <dd
                class="mt-1 font-medium"
                :class="summary?.kill_switch_active ? 'text-error' : 'text-highlighted'"
              >
                {{ summary ? (summary.kill_switch_active ? 'Ativo' : 'Desligado') : 'Indisponível' }}
              </dd>
            </div>
            <div>
              <dt class="text-muted">
                Limite de uso
              </dt>
              <dd class="mt-1 font-medium text-highlighted">
                {{ summary ? (summary.usage_allowed ? (summary.usage_alert_reached ? 'Próximo do limite' : 'Disponível') : 'Bloqueado') : 'Indisponível' }}
              </dd>
            </div>
          </dl>

          <details class="mt-4 border-t border-default pt-4 text-sm">
            <summary class="cursor-pointer font-medium text-muted hover:text-highlighted">
              Ver endpoints oficiais
            </summary>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
              <div>
                <dt class="text-muted">
                  OAuth
                </dt>
                <dd class="mt-1 break-all font-mono text-xs text-highlighted">
                  {{ configuration.endpoints?.oauth_token_url || 'Não informado' }}
                </dd>
              </div>
              <div>
                <dt class="text-muted">
                  Integra Contador
                </dt>
                <dd class="mt-1 break-all font-mono text-xs text-highlighted">
                  {{ configuration.endpoints?.api_base_url || 'Não informado' }}
                </dd>
              </div>
            </dl>
          </details>
        </UPageCard>

        <UAlert
          v-if="environment === 'TRIAL'"
          color="warning"
          variant="subtle"
          icon="i-lucide-flask-conical"
          title="Demonstração SERPRO — não confirma transmissão, aceite ou evidência fiscal real pela SERPRO."
        />

        <UPageCard
          title="Credenciais"
          variant="subtle"
          data-testid="serpro-config-credentials"
        >
          <div
            v-if="environment === 'PRODUCTION'"
            class="mb-4 flex flex-wrap items-start justify-between gap-3"
            data-testid="serpro-production-status"
          >
            <div class="min-w-0">
              <p class="text-sm text-muted">
                {{ productionOnboarding?.office_id ? `Office #${productionOnboarding.office_id}` : 'Contexto não selecionado' }}
              </p>
              <p
                v-if="productionState?.status === 'ACTION_REQUIRED' || productionState?.status === 'FAILED'"
                class="mt-1 text-sm text-muted"
                :data-testid="productionState?.status === 'FAILED' ? 'serpro-prod-failed' : 'serpro-prod-action-required'"
              >
                {{ productionState.error?.message
                  || (productionState.status === 'FAILED'
                    ? 'Ativação não concluída. Revise os dados e tente novamente.'
                    : 'Há uma ação operacional pendente antes da leitura inicial.') }}
              </p>
            </div>
            <UBadge
              :color="productionStatusColor(productionState?.status)"
              variant="subtle"
              size="lg"
            >
              {{ productionStatusLabel(productionState?.status) }}
            </UBadge>
          </div>

          <UAlert
            v-if="environment === 'PRODUCTION' && productionOnboarding && !productionOnboarding.enabled"
            class="mb-4"
            color="warning"
            variant="subtle"
            icon="i-lucide-lock"
            title="Onboarding produtivo desabilitado por feature flag — o formulário permanece visível; a ativação só conclui com a flag ligada."
            data-testid="serpro-prod-disabled"
          />

          <div class="mb-4 grid gap-4 sm:grid-cols-2">
            <UFormField
              label="Certificado do contratante (.pfx ou .p12)"
              required
              class="sm:col-span-2"
            >
              <UFileUpload
                v-model="upload.pfx"
                accept=".pfx,.p12,application/x-pkcs12"
                label="Selecione ou arraste o arquivo"
                icon="i-lucide-file-key-2"
                layout="list"
                position="inside"
                class="w-full"
                :ui="{ base: 'min-h-28' }"
                data-testid="serpro-config-pfx"
              />
            </UFormField>
            <UFormField
              label="Senha do PFX"
              required
            >
              <UInput
                v-model="upload.password"
                type="password"
                class="w-full"
                autocomplete="new-password"
                data-testid="serpro-config-pfx-password"
              />
            </UFormField>
            <UFormField
              label="Consumer Key"
              required
            >
              <UInput
                v-model="upload.consumer_key"
                class="w-full"
                autocomplete="off"
                data-testid="serpro-config-ck"
              />
            </UFormField>
            <UFormField
              label="Consumer Secret"
              required
            >
              <UInput
                v-model="upload.consumer_secret"
                type="password"
                class="w-full"
                autocomplete="new-password"
                data-testid="serpro-config-cs"
              />
            </UFormField>
          </div>

          <div
            v-if="environment === 'PRODUCTION'"
            class="mb-4 rounded-md border border-default p-3"
          >
            <UCheckbox
              v-model="upload.consent_granted"
              :label="productionConsent?.text || 'Autorizo a ativação de Produção SERPRO para o escritório selecionado.'"
              data-testid="serpro-prod-consent"
            />
            <p
              v-if="productionConsent?.version"
              class="mt-2 font-mono text-xs text-muted"
            >
              {{ productionConsent.version }} · {{ productionConsent.text_sha256 }}
            </p>
          </div>

          <UButton
            :label="environment === 'TRIAL' ? 'Salvar e ativar' : 'Ativar Produção'"
            :icon="environment === 'TRIAL' ? 'i-lucide-circle-check' : 'i-lucide-shield-check'"
            :loading="acting"
            data-testid="serpro-config-upload"
            @click="submitUpload"
          />

          <div
            v-if="configuration.active_credential_version"
            class="mt-6 rounded-lg bg-elevated/50 p-4 text-sm"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="font-medium text-highlighted">
                Versão ativa · v{{ configuration.active_credential_version.version_number }}
              </p>
              <UBadge color="success" variant="subtle">
                Ativa
              </UBadge>
            </div>
            <p class="mt-2 text-muted">
              Key …{{ configuration.active_credential_version.consumer_key_last4 || 'Não informada' }}
              · CNPJ {{ configuration.active_credential_version.contractor_cnpj_masked || 'não informado' }}
            </p>
          </div>

          <div class="mt-6 flex items-center justify-between gap-3">
            <h3 class="font-medium text-highlighted">
              Versões em preparação
            </h3>
            <UBadge color="neutral" variant="subtle">
              {{ pendingVersions.length }}
            </UBadge>
          </div>

          <ul
            v-if="pendingVersions.length"
            class="mt-3 divide-y divide-default"
          >
            <li
              v-for="v in pendingVersions"
              :key="v.id"
              class="flex flex-wrap items-center justify-between gap-3 py-3"
              :data-testid="`serpro-config-version-${v.id}`"
            >
              <div class="text-sm">
                <span class="font-medium">v{{ v.version_number }}</span>
                <UBadge
                  class="ms-2"
                  variant="subtle"
                  :color="credentialStatusColor(v.status)"
                >
                  {{ credentialStatusLabel(v.status) }}
                </UBadge>
                <span class="ms-2 text-muted">…{{ v.consumer_key_last4 || '—' }}</span>
                <span
                  v-if="v.has_recent_connection_test"
                  class="ms-2 text-success"
                >teste OK</span>
              </div>
              <div class="flex flex-wrap gap-2">
                <UButton
                  v-if="v.status === 'PENDING'"
                  size="xs"
                  variant="soft"
                  label="Verificar certificado"
                  :loading="acting"
                  @click="verifyVersion(v)"
                />
                <UButton
                  v-else-if="v.status === 'VERIFIED' && !v.has_recent_connection_test"
                  size="xs"
                  variant="soft"
                  label="Testar OAuth"
                  :loading="acting"
                  @click="testConnection(v)"
                />
                <UButton
                  v-else-if="v.status === 'VERIFIED' && v.has_recent_connection_test"
                  size="xs"
                  color="primary"
                  label="Cutover"
                  :loading="acting"
                  @click="cutover(v)"
                />
                <UButton
                  v-else-if="v.status === 'ACTIVE'"
                  size="xs"
                  color="neutral"
                  variant="outline"
                  label="Testar conexão"
                  :loading="acting"
                  @click="testConnection(v)"
                />
              </div>
            </li>
          </ul>
          <p
            v-else
            class="mt-4 text-sm text-muted"
          >
            Nenhuma versão aguardando ação neste ambiente.
          </p>
        </UPageCard>

        <UPageCard
          title="Limites de uso"
          variant="subtle"
          data-testid="serpro-config-limits"
        >
          <div class="grid gap-4 sm:grid-cols-3">
            <UFormField
              label="Dia inicial (1–28)"
            >
              <UInputNumber
                v-model="limitsForm.cycle_start_day"
                :min="1"
                :max="28"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Alerta (%)"
            >
              <UInputNumber
                v-model="limitsForm.alert_percent"
                :min="1"
                :max="100"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Teto do ciclo"
            >
              <UInputNumber
                v-model="limitsForm.global_limit_quantity"
                :min="1"
                placeholder="Informe o teto"
                class="w-full"
              />
            </UFormField>
          </div>
          <UButton
            class="mt-4"
            label="Salvar limites"
            :loading="acting"
            @click="saveLimits"
          />
        </UPageCard>
      </template>

      <div
        v-if="!loading && !configuration && !loadError"
        class="flex min-h-40 flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-default text-center text-sm text-muted"
        role="status"
        data-testid="serpro-config-empty"
      >
        <UIcon name="i-lucide-settings-2" class="size-7" aria-hidden="true" />
        Nenhuma configuração carregada.
      </div>

      <ShellFormModal
        v-model:open="passwordModalOpen"
        title="Reconfirmar senha"
        submit-label="Confirmar"
        :loading="acting"
        :show-default-footer="false"
        test-id="serpro-config-password-modal"
        @cancel="cancelPasswordConfirmation"
        @submit="submitPassword"
      >
        <template #body>
          <UFormField
            label="Senha"
            required
          >
            <UInput
              v-model="passwordInput"
              type="password"
              autocomplete="current-password"
              data-testid="serpro-config-session-password"
              @keyup.enter="submitPassword"
            />
          </UFormField>
        </template>
        <template #footer>
          <ShellModalFooter
            submit-label="Confirmar"
            submit-test-id="serpro-config-password-submit"
            :loading="acting"
            @cancel="cancelPasswordConfirmation"
            @submit="submitPassword"
          />
        </template>
      </ShellFormModal>

      <SerproOwnerConfirmModal
        v-model:open="cutoverOwnerOpen"
        action="CREDENTIAL_CUTOVER"
        title="Confirmar cutover da credencial SERPRO"
        :expected-phrase="cutoverPhrase"
        data-testid="serpro-config-cutover-owner-modal"
        @confirm="confirmCutover"
      />
    </div>
  </div>
</template>
