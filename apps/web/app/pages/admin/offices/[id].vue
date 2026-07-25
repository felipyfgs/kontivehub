<script setup lang="ts">
/**
 * Detalhe admin de Office (lifecycle + ativação do 1º admin).
 * Arquétipo: settings (UDashboardPanel + UPageCard naked/subtle).
 * Fonte: .local/reference/nuxt-dashboard-template/app/pages/settings.vue + settings/index.vue
 */
import type { ActivationMethod, CredentialDeliveryPayload, PlatformOfficeAdminDetail } from '~/types/api'
import { apiErrorMessage } from '~/utils/api-error'

const route = useRoute()
const api = useApi()
const toast = useToast()
const { sessionEpoch, canAccessPlatformAdmin } = useDashboard()

const officeId = computed(() => Number(route.params.id))
const office = ref<PlatformOfficeAdminDetail | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)
const acting = ref(false)
const secret = ref<CredentialDeliveryPayload | null>(null)

const reconfirmPassword = ref('')
const method = ref<ActivationMethod>('MANUAL_LINK')
const correctOpen = ref(false)
const correctName = ref('')
const correctEmail = ref('')

const methodItems = [
  { label: 'Link manual (7 dias)', value: 'MANUAL_LINK' as ActivationMethod },
  { label: 'Senha provisória (7 dias)', value: 'TEMPORARY_PASSWORD' as ActivationMethod }
]

const isPending = computed(() => office.value?.lifecycle_status === 'PENDING_ACTIVATION')
const isActive = computed(() => office.value?.lifecycle_status === 'ACTIVE')
const isSuspended = computed(() => office.value?.lifecycle_status === 'SUSPENDED')
const isDeprovisioned = computed(() => office.value?.lifecycle_status === 'DEPROVISIONED')

const pageTitle = computed(() => office.value?.name || 'Escritório')

const lifecycleLabel = computed(() => {
  const status = office.value?.lifecycle_status
  if (status === 'PENDING_ACTIVATION') return 'Pendente de ativação'
  if (status === 'ACTIVE') return 'Ativo'
  if (status === 'SUSPENDED') return 'Suspenso'
  if (status === 'DEPROVISIONED') return 'Desprovisionado'
  return status || '—'
})

const lifecycleColor = computed((): 'warning' | 'success' | 'error' | 'neutral' => {
  const status = office.value?.lifecycle_status
  if (status === 'PENDING_ACTIVATION') return 'warning'
  if (status === 'ACTIVE') return 'success'
  if (status === 'SUSPENDED') return 'error'
  if (status === 'DEPROVISIONED') return 'neutral'
  return 'neutral'
})

const planLabel = computed(() => {
  const plan = office.value?.subscription?.plan
  if (!plan) return '—'
  if (plan === 'STARTER') return 'Starter'
  if (plan === 'PROFESSIONAL') return 'Professional'
  if (plan === 'ENTERPRISE') return 'Enterprise'
  return String(plan)
})

const activationMethodLabel = computed(() => {
  const m = office.value?.activation?.method
  if (m === 'MANUAL_LINK') return 'Link manual'
  if (m === 'TEMPORARY_PASSWORD') return 'Senha provisória'
  return m || '—'
})

const activationStatusLabel = computed(() => {
  const s = office.value?.activation?.status
  if (!s) return '—'
  if (s === 'PENDING') return 'Pendente'
  if (s === 'CONSUMED') return 'Consumida'
  if (s === 'EXPIRED') return 'Expirada'
  if (s === 'REVOKED') return 'Revogada'
  return String(s)
})

let loadSeq = 0

async function load() {
  if (!canAccessPlatformAdmin.value) {
    loadError.value = 'Acesso restrito a administradores da plataforma.'
    office.value = null
    return
  }
  if (!Number.isFinite(officeId.value) || officeId.value <= 0) {
    loadError.value = 'Identificador de escritório inválido.'
    office.value = null
    return
  }

  const seq = ++loadSeq
  const epoch = sessionEpoch.value
  loading.value = true
  loadError.value = null
  try {
    const res = await api.platform.offices.show(officeId.value)
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    office.value = res.data
    if (office.value?.first_admin) {
      correctName.value = office.value.first_admin.name || ''
      correctEmail.value = office.value.first_admin.email || ''
    }
  } catch (e) {
    if (seq !== loadSeq || epoch !== sessionEpoch.value) return
    office.value = null
    loadError.value = apiErrorMessage(e, 'Falha ao carregar o escritório.')
  } finally {
    if (seq === loadSeq && epoch === sessionEpoch.value) {
      loading.value = false
    }
  }
}

function showSecret(payload: CredentialDeliveryPayload) {
  if (payload.credential_delivery === 'delivered'
    && (payload.activation_url || payload.temporary_password)) {
    secret.value = payload
  } else {
    secret.value = null
    if (payload.credential_delivery === 'regeneration_required') {
      toast.add({
        title: 'Segredo não recuperável. Use Regenerar acesso.',
        color: 'warning'
      })
    }
  }
}

function clearSecret() {
  secret.value = null
}

async function ensurePassword(): Promise<boolean> {
  if (!reconfirmPassword.value) {
    toast.add({ title: 'Informe sua senha para continuar.', color: 'warning' })
    return false
  }
  try {
    await api.confirmPassword(reconfirmPassword.value)
    return true
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Senha inválida.'), color: 'error' })
    return false
  }
}

async function regenerate() {
  if (!(await ensurePassword())) return
  acting.value = true
  try {
    const res = await api.platform.offices.regenerateActivation(officeId.value, {
      method: method.value
    })
    showSecret(res.data)
    toast.add({ title: 'Acesso regenerado.', color: 'success' })
    reconfirmPassword.value = ''
    await load()
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Falha ao regenerar.'), color: 'error' })
  } finally {
    acting.value = false
  }
}

async function correctFirstAdmin() {
  if (!(await ensurePassword())) return
  acting.value = true
  try {
    const res = await api.platform.offices.updateFirstAdmin(officeId.value, {
      name: correctName.value.trim(),
      email: correctEmail.value.trim(),
      method: method.value
    })
    showSecret(res.data)
    toast.add({ title: 'Primeiro administrador corrigido.', color: 'success' })
    correctOpen.value = false
    reconfirmPassword.value = ''
    await load()
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Falha ao corrigir.'), color: 'error' })
  } finally {
    acting.value = false
  }
}

watch(sessionEpoch, () => {
  clearSecret()
  void load()
})
watch(officeId, () => {
  clearSecret()
  void load()
})
onMounted(load)
onBeforeUnmount(clearSecret)
</script>

<template>
  <UDashboardPanel
    id="admin-office-detail"
    data-testid="admin-office-detail"
    :ui="{ body: 'lg:py-12' }"
  >
    <template #header>
      <UDashboardNavbar
        :title="pageTitle"
        data-testid="page-navbar"
      >
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <div class="flex items-center gap-2">
            <UBadge
              v-if="office"
              size="sm"
              variant="subtle"
              :color="lifecycleColor"
              :label="lifecycleLabel"
              data-testid="admin-office-lifecycle-badge"
            />
            <UButton
              to="/admin/offices"
              color="neutral"
              variant="ghost"
              icon="i-lucide-arrow-left"
              label="Lista"
              data-testid="admin-office-back"
            />
          </div>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <DashboardContent
        width="comfortable"
        class="gap-4 sm:gap-6 lg:gap-12"
      >
        <div
          v-if="loading && !office"
          class="space-y-4"
          data-testid="admin-office-loading"
        >
          <USkeleton class="h-10 w-2/3" />
          <USkeleton class="h-36 w-full" />
          <USkeleton class="h-36 w-full" />
        </div>

        <UAlert
          v-else-if="loadError"
          color="error"
          icon="i-lucide-circle-x"
          :title="loadError"
          :actions="[{ label: 'Tentar novamente', color: 'neutral', variant: 'subtle', onClick: load }]"
          data-testid="admin-office-error"
        />

        <template v-else-if="office">
          <ActivationOneTimeSecret
            v-if="secret"
            :activation-url="secret.activation_url"
            :temporary-password="secret.temporary_password"
            :expires-at="secret.expires_at"
            :method="secret.method"
          />

          <!-- Cabeçalho de seção (settings naked + horizontal) -->
          <UPageCard
            title="Resumo"
            variant="naked"
            orientation="horizontal"
            class="mb-0"
            data-testid="admin-office-summary-header"
          >
            <UButton
              color="neutral"
              variant="outline"
              icon="i-lucide-refresh-cw"
              label="Atualizar"
              size="sm"
              class="w-fit lg:ms-auto"
              :loading="loading"
              data-testid="admin-office-refresh"
              @click="() => { void load() }"
            />
          </UPageCard>

          <UPageCard
            variant="subtle"
            data-testid="admin-office-summary"
          >
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
              <div class="space-y-1">
                <dt class="text-muted">
                  ID
                </dt>
                <dd class="font-mono text-highlighted">
                  {{ office.id }}
                </dd>
              </div>
              <div class="space-y-1">
                <dt class="text-muted">
                  Estado
                </dt>
                <dd>
                  <UBadge
                    size="sm"
                    variant="subtle"
                    :color="lifecycleColor"
                    :label="lifecycleLabel"
                  />
                </dd>
              </div>
              <div class="space-y-1">
                <dt class="text-muted">
                  Slug
                </dt>
                <dd class="break-all font-mono text-highlighted">
                  {{ office.slug }}
                </dd>
              </div>
              <div class="space-y-1">
                <dt class="text-muted">
                  Plano
                </dt>
                <dd class="text-highlighted">
                  {{ planLabel }}
                </dd>
              </div>
              <div class="space-y-1 sm:col-span-2">
                <dt class="text-muted">
                  Ativação
                </dt>
                <dd class="text-highlighted">
                  <template v-if="office.activation">
                    {{ activationMethodLabel }} · {{ activationStatusLabel }}
                    <span
                      v-if="office.activation.expires_at"
                      class="text-muted"
                    >
                      · expira {{ formatDateTime(office.activation.expires_at) }}
                    </span>
                  </template>
                  <template v-else>
                    —
                  </template>
                </dd>
              </div>
              <div
                v-if="office.profile?.cnpj"
                class="space-y-1"
              >
                <dt class="text-muted">
                  CNPJ
                </dt>
                <dd class="font-mono text-highlighted">
                  {{ office.profile.cnpj }}
                </dd>
              </div>
              <div
                v-if="office.profile?.legal_name"
                class="space-y-1"
              >
                <dt class="text-muted">
                  Razão social
                </dt>
                <dd class="text-highlighted">
                  {{ office.profile.legal_name }}
                </dd>
              </div>
              <div
                v-if="office.profile?.institutional_email"
                class="space-y-1"
              >
                <dt class="text-muted">
                  E-mail institucional
                </dt>
                <dd class="break-all text-highlighted">
                  {{ office.profile.institutional_email }}
                </dd>
              </div>
              <div
                v-if="office.profile?.institutional_phone"
                class="space-y-1"
              >
                <dt class="text-muted">
                  Telefone
                </dt>
                <dd class="text-highlighted">
                  {{ office.profile.institutional_phone }}
                </dd>
              </div>
              <div
                v-if="office.created_at"
                class="space-y-1"
              >
                <dt class="text-muted">
                  Criado em
                </dt>
                <dd class="text-highlighted">
                  {{ formatDateTime(office.created_at) }}
                </dd>
              </div>
            </dl>
          </UPageCard>

          <UAlert
            v-if="isActive && !isPending"
            color="success"
            variant="subtle"
            icon="i-lucide-circle-check"
            title="Escritório ativo"
            data-testid="admin-office-active-hint"
          >
            <template #description>
              Operação tenant e equipe ficam no contexto do escritório. Aqui só metadados globais e lifecycle.
            </template>
          </UAlert>

          <UAlert
            v-else-if="isSuspended"
            color="error"
            variant="subtle"
            icon="i-lucide-ban"
            title="Escritório suspenso"
            data-testid="admin-office-suspended-hint"
          >
            <template #description>
              Seleção e operações tenant estão bloqueadas. Reativação exige fluxo de lifecycle da plataforma.
            </template>
          </UAlert>

          <UAlert
            v-else-if="isDeprovisioned"
            color="neutral"
            variant="subtle"
            icon="i-lucide-archive"
            title="Escritório desprovisionado"
            data-testid="admin-office-deprovisioned-hint"
          >
            <template #description>
              Estado terminal: metadados e auditoria preservados; sem operação tenant.
            </template>
          </UAlert>

          <div
            v-if="isPending"
            class="grid gap-4 lg:grid-cols-2 lg:gap-6"
            data-testid="admin-office-pending-actions"
          >
            <div class="flex flex-col gap-4">
              <UPageCard
                title="Acesso inicial"
                variant="naked"
                orientation="horizontal"
                class="mb-0"
              />
              <UPageCard
                variant="subtle"
                data-testid="admin-office-regenerate-card"
              >
                <div class="space-y-4">
                  <p class="text-sm text-muted">
                    Gera novo link ou senha provisória para o primeiro administrador. O segredo só aparece uma vez.
                  </p>
                  <UFormField
                    label="Método de entrega"
                    class="flex max-sm:flex-col justify-between items-start gap-2 sm:gap-4"
                  >
                    <USelect
                      v-model="method"
                      :items="methodItems"
                      value-key="value"
                      label-key="label"
                      class="w-full sm:w-56"
                      data-testid="admin-office-method"
                    />
                  </UFormField>
                  <USeparator />
                  <UFormField
                    label="Senha atual"
                    required
                    class="flex max-sm:flex-col justify-between items-start gap-2 sm:gap-4"
                  >
                    <UInput
                      v-model="reconfirmPassword"
                      type="password"
                      autocomplete="current-password"
                      class="w-full sm:w-56"
                      data-testid="admin-office-reconfirm"
                    />
                  </UFormField>
                  <div class="flex justify-end pt-1">
                    <UButton
                      label="Regenerar acesso"
                      icon="i-lucide-refresh-cw"
                      :loading="acting"
                      data-testid="admin-office-regenerate"
                      @click="() => { void regenerate() }"
                    />
                  </div>
                </div>
              </UPageCard>
            </div>

            <div class="flex flex-col gap-4">
              <UPageCard
                title="Primeiro administrador"
                variant="naked"
                orientation="horizontal"
                class="mb-0"
              />
              <UPageCard
                variant="subtle"
                class="flex-1"
                data-testid="admin-office-first-admin-card"
              >
                <div class="flex h-full flex-col gap-4">
                  <dl
                    v-if="office.first_admin"
                    class="grid gap-3 text-sm"
                  >
                    <div class="space-y-1">
                      <dt class="text-muted">
                        Nome
                      </dt>
                      <dd class="text-highlighted">
                        {{ office.first_admin.name || '—' }}
                      </dd>
                    </div>
                    <div class="space-y-1">
                      <dt class="text-muted">
                        E-mail
                      </dt>
                      <dd class="break-all text-highlighted">
                        {{ office.first_admin.email || '—' }}
                      </dd>
                    </div>
                    <div class="space-y-1">
                      <dt class="text-muted">
                        Situação
                      </dt>
                      <dd>
                        <UBadge
                          size="sm"
                          variant="subtle"
                          :color="office.first_admin.is_active ? 'success' : 'neutral'"
                          :label="office.first_admin.is_active ? 'Ativo' : 'Inativo'"
                        />
                      </dd>
                    </div>
                  </dl>
                  <UEmpty
                    v-else
                    icon="i-lucide-user-x"
                    title="Sem administrador inicial"
                    class="py-4"
                  />
                  <UButton
                    class="mt-auto w-fit"
                    label="Corrigir dados"
                    color="neutral"
                    variant="outline"
                    icon="i-lucide-user-pen"
                    data-testid="admin-office-correct-open"
                    @click="() => { correctOpen = true }"
                  />
                </div>
              </UPageCard>
            </div>
          </div>
        </template>

        <ShellFormModal
          v-model:open="correctOpen"
          title="Corrigir primeiro administrador"
          description="Revoga o acesso anterior."
          :loading="acting"
          :show-default-footer="false"
          test-id="admin-office-correct-modal"
          @cancel="() => { correctOpen = false }"
        >
          <template #body>
            <div class="space-y-4">
              <UFormField
                label="Nome"
                required
              >
                <UInput
                  v-model="correctName"
                  class="w-full"
                  data-testid="admin-office-correct-name"
                />
              </UFormField>
              <UFormField
                label="E-mail"
                required
              >
                <UInput
                  v-model="correctEmail"
                  type="email"
                  class="w-full"
                  data-testid="admin-office-correct-email"
                />
              </UFormField>
              <UFormField label="Método de entrega">
                <USelect
                  v-model="method"
                  :items="methodItems"
                  value-key="value"
                  label-key="label"
                  class="w-full"
                />
              </UFormField>
              <UFormField
                label="Senha atual"
                required
              >
                <UInput
                  v-model="reconfirmPassword"
                  type="password"
                  autocomplete="current-password"
                  class="w-full"
                  data-testid="admin-office-correct-reconfirm"
                />
              </UFormField>
            </div>
          </template>
          <template #footer>
            <ShellModalFooter
              submit-label="Corrigir"
              :loading="acting"
              submit-test-id="admin-office-correct-submit"
              @cancel="() => { correctOpen = false }"
              @submit="() => { void correctFirstAdmin() }"
            />
          </template>
        </ShellFormModal>
      </DashboardContent>
    </template>
  </UDashboardPanel>
</template>
