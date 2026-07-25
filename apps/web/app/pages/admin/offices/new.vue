<script setup lang="ts">
/**
 * Wizard enxuto: Dados → 1º admin → Revisão.
 * Plano (STARTER) e entrega (MANUAL_LINK) ficam default; ajustáveis depois.
 */
import type { StepperItem } from '@nuxt/ui'
import type {
  ActivationMethod,
  CreatePlatformOfficeResult,
  SubscriptionPlanCode
} from '~/types/api'
import { apiErrorMessage } from '~/utils/api-error'

const api = useApi()
const toast = useToast()
const router = useRouter()

const step = ref(0)
const submitting = ref(false)
const reconfirmPassword = ref('')
const formError = ref('')
const result = ref<CreatePlatformOfficeResult | null>(null)
/** Chave estável para retries do mesmo envio. */
const idempotencyKey = ref(
  typeof crypto !== 'undefined' && crypto.randomUUID
    ? crypto.randomUUID()
    : `office-${Date.now()}-${Math.random().toString(36).slice(2)}`
)

const form = reactive({
  name: '',
  legal_name: '',
  institutional_email: '',
  institutional_phone: '',
  admin_name: '',
  admin_email: ''
})

/** Defaults silenciosos — não pedidos no wizard. */
const defaultPlan = 'STARTER' as SubscriptionPlanCode
const defaultMethod = 'MANUAL_LINK' as ActivationMethod

const steps = computed<StepperItem[]>(() => [
  {
    title: 'Dados',
    icon: 'i-lucide-building-2',
    value: 0
  },
  {
    title: 'Administrador',
    icon: 'i-lucide-user-cog',
    value: 1
  },
  {
    title: 'Revisão',
    icon: 'i-lucide-check-check',
    value: 2
  }
])

const lastStep = computed(() => steps.value.length - 1)

const secretDelivered = computed(() =>
  result.value?.credential_delivery === 'delivered'
  && Boolean(result.value.activation_url || result.value.temporary_password)
)

function validateStep(index: number): string | null {
  if (index === 0) {
    if (!form.name.trim()) return 'Informe o nome do escritório.'
    if (!form.legal_name.trim()) return 'Informe a razão social.'
    if (!form.institutional_email.trim()) return 'Informe o e-mail institucional.'
    if (!form.institutional_phone.trim()) return 'Informe o telefone institucional.'
  }
  if (index === 1) {
    if (!form.admin_name.trim()) return 'Informe o nome do administrador.'
    if (!form.admin_email.trim()) return 'Informe o e-mail do administrador.'
  }
  return null
}

function next() {
  formError.value = ''
  const err = validateStep(step.value)
  if (err) {
    formError.value = err
    return
  }
  if (step.value < lastStep.value) step.value += 1
}

function back() {
  formError.value = ''
  if (step.value > 0) step.value -= 1
}

async function submit() {
  formError.value = ''
  for (let i = 0; i < lastStep.value; i++) {
    const err = validateStep(i)
    if (err) {
      formError.value = err
      step.value = i
      return
    }
  }
  if (!reconfirmPassword.value) {
    formError.value = 'Confirme sua senha para criar o escritório.'
    return
  }

  submitting.value = true
  try {
    await api.confirmPassword(reconfirmPassword.value)
    const res = await api.platform.offices.create({
      name: form.name.trim(),
      profile: {
        cnpj: '',
        legal_name: form.legal_name.trim(),
        institutional_email: form.institutional_email.trim(),
        institutional_phone: form.institutional_phone.trim()
      },
      plan: defaultPlan,
      admin_name: form.admin_name.trim(),
      admin_email: form.admin_email.trim(),
      method: defaultMethod,
      idempotency_key: idempotencyKey.value
    })
    result.value = res.data
    reconfirmPassword.value = ''

    if (res.data.credential_delivery === 'regeneration_required') {
      toast.add({
        title: 'Escritório já criado. Regeneração necessária.',
        color: 'warning'
      })
      const id = res.data.office?.id
      if (id) {
        await router.replace(`/admin/offices/${id}`)
        return
      }
    }

    toast.add({ title: 'Escritório criado.', color: 'success' })
  } catch (e) {
    formError.value = apiErrorMessage(e, 'Não foi possível criar o escritório.')
  } finally {
    submitting.value = false
  }
}

function goToDetail() {
  const id = result.value?.office?.id
  if (id) void router.push(`/admin/offices/${id}`)
  else void router.push('/admin/offices')
}

onBeforeUnmount(() => {
  result.value = null
})
</script>

<template>
  <UDashboardPanel
    id="admin-offices-new"
    data-testid="admin-offices-new"
    :ui="{ body: 'lg:py-12' }"
  >
    <template #header>
      <UDashboardNavbar
        title="Novo escritório"
        data-testid="page-navbar"
      >
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            to="/admin/offices"
            color="neutral"
            variant="ghost"
            icon="i-lucide-arrow-left"
            label="Escritórios"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <DashboardContent
        width="comfortable"
        class="gap-4 sm:gap-6"
      >
        <template v-if="result && secretDelivered">
          <UPageCard
            title="Escritório criado"
            :description="result.office?.name"
            variant="subtle"
            data-testid="admin-office-created"
          >
            <ActivationOneTimeSecret
              class="mt-2"
              :activation-url="result.activation_url"
              :temporary-password="result.temporary_password"
              :expires-at="result.expires_at"
              :method="result.method"
            />
            <div class="mt-4 flex flex-wrap gap-2">
              <UButton
                label="Abrir detalhe"
                icon="i-lucide-building-2"
                @click="goToDetail"
              />
              <UButton
                to="/admin/offices"
                color="neutral"
                variant="outline"
                label="Voltar à lista"
              />
            </div>
          </UPageCard>
        </template>

        <template v-else>
          <div
            class="sm:hidden"
            data-testid="admin-office-mobile-progress"
          >
            <div class="mb-2 text-right text-sm text-muted">
              Etapa {{ step + 1 }} de {{ steps.length }}
            </div>
            <UProgress
              :model-value="((step + 1) / steps.length) * 100"
              :max="100"
              size="sm"
              :aria-label="`Etapa ${step + 1} de ${steps.length}`"
            />
          </div>

          <div class="hidden sm:block">
            <UStepper
              v-model="step"
              :items="steps"
              class="w-full"
              color="primary"
              size="sm"
              data-testid="admin-office-stepper"
            />
          </div>

          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            :title="formError"
            :close="{ onClick: () => { formError = '' } }"
            data-testid="admin-office-wizard-error"
          />

          <UPageCard
            :title="steps[step]?.title"
            variant="subtle"
            :ui="{ container: 'sm:p-6 gap-y-5' }"
          >
            <div
              v-if="step === 0"
              class="space-y-4"
              data-testid="wizard-step-office"
            >
              <UFormField
                label="Nome de exibição"
                required
              >
                <UInput
                  v-model="form.name"
                  class="w-full"
                  autocomplete="organization"
                  data-testid="wizard-office-name"
                />
              </UFormField>
              <UFormField
                label="Razão social"
                required
              >
                <UInput
                  v-model="form.legal_name"
                  class="w-full"
                  autocomplete="organization"
                  data-testid="wizard-office-legal-name"
                />
              </UFormField>
              <UFormField
                label="E-mail institucional"
                required
              >
                <UInput
                  v-model="form.institutional_email"
                  type="email"
                  class="w-full"
                  autocomplete="email"
                  data-testid="wizard-office-email"
                />
              </UFormField>
              <UFormField
                label="Telefone institucional"
                required
              >
                <UInput
                  v-model="form.institutional_phone"
                  class="w-full"
                  autocomplete="tel"
                  data-testid="wizard-office-phone"
                />
              </UFormField>
            </div>

            <div
              v-else-if="step === 1"
              class="space-y-4"
              data-testid="wizard-step-admin"
            >
              <UFormField
                label="Nome"
                required
              >
                <UInput
                  v-model="form.admin_name"
                  class="w-full"
                  autocomplete="name"
                  data-testid="wizard-admin-name"
                />
              </UFormField>
              <UFormField
                label="E-mail"
                required
              >
                <UInput
                  v-model="form.admin_email"
                  type="email"
                  class="w-full"
                  autocomplete="email"
                  data-testid="wizard-admin-email"
                />
              </UFormField>
            </div>

            <div
              v-else
              class="space-y-4"
              data-testid="wizard-step-review"
            >
              <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                  <dt class="text-muted">
                    Escritório
                  </dt>
                  <dd class="text-highlighted">
                    {{ form.name }}
                  </dd>
                </div>
                <div>
                  <dt class="text-muted">
                    Razão social
                  </dt>
                  <dd class="text-highlighted">
                    {{ form.legal_name }}
                  </dd>
                </div>
                <div class="sm:col-span-2">
                  <dt class="text-muted">
                    1º admin
                  </dt>
                  <dd class="text-highlighted">
                    {{ form.admin_name }} · {{ form.admin_email }}
                  </dd>
                </div>
              </dl>
              <UFormField
                label="Senha atual"
                required
              >
                <UInput
                  v-model="reconfirmPassword"
                  type="password"
                  autocomplete="current-password"
                  class="w-full"
                  data-testid="wizard-reconfirm"
                />
              </UFormField>
            </div>

            <div class="flex flex-wrap justify-between gap-2 border-t border-default pt-4">
              <UButton
                v-if="step > 0"
                label="Voltar"
                color="neutral"
                variant="ghost"
                icon="i-lucide-arrow-left"
                :disabled="submitting"
                @click="back"
              />
              <div
                v-else
                class="flex-1"
              />
              <UButton
                v-if="step < lastStep"
                label="Continuar"
                color="primary"
                trailing-icon="i-lucide-arrow-right"
                data-testid="wizard-next"
                @click="next"
              />
              <UButton
                v-else
                label="Criar escritório"
                color="primary"
                icon="i-lucide-check"
                :loading="submitting"
                data-testid="wizard-submit"
                @click="() => { void submit() }"
              />
            </div>
          </UPageCard>
        </template>
      </DashboardContent>
    </template>
  </UDashboardPanel>
</template>
