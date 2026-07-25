<script setup lang="ts">
/**
 * Onboarding inicial da plataforma (instalação vazia).
 * Token preferencialmente via `#token=` (removido da URL); se ausente e
 * a instalação ainda estiver pristine, permite colar o token de deploy.
 */
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import {
  consumeActivationTokenFromLocation,
  extractActivationTokenFromHash
} from '~/utils/activation'
import { apiErrorMessage } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

useSeoMeta({
  title: 'Configurar plataforma · KontiveHub',
  description: 'Primeiro administrador global da instalação'
})

const api = useApi()
const { refreshIdentity } = useSanctumAuth()

const token = ref<string | null>(null)
const tokenDraft = ref('')
const checking = ref(true)
const available = ref(false)
const error = ref('')
const loading = ref(false)
const tokenError = ref('')

const schema = z.object({
  organization_name: z.string('Informe o nome da organização').min(2, 'Informe o nome da organização').max(255),
  email: z.email('Informe um e-mail válido'),
  password: z.string('Informe a senha').min(8, 'Mínimo de 8 caracteres'),
  password_confirmation: z.string('Confirme a senha').min(1, 'Confirme a senha')
}).refine(d => d.password === d.password_confirmation, {
  message: 'As senhas não coincidem',
  path: ['password_confirmation']
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  organization_name: '',
  email: '',
  password: '',
  password_confirmation: ''
})

onMounted(async () => {
  token.value = consumeActivationTokenFromLocation()
  try {
    const res = await api.onboarding.status()
    available.value = res.data?.available === true
  } catch {
    available.value = false
  } finally {
    checking.value = false
  }
})

/** Instalação já configurada — não há o que criar aqui. */
const trulyUnavailable = computed(() => !checking.value && !available.value)

/** Instalação pristine, mas ainda falta o token de deploy. */
const needsToken = computed(() => !checking.value && available.value && !token.value)

function applyTokenDraft() {
  tokenError.value = ''
  const raw = tokenDraft.value.trim()
  // Aceita URL completa com #token=…, fragmento token=… ou o token puro.
  let value = raw
  const hashIdx = raw.indexOf('#')
  if (hashIdx >= 0) {
    value = extractActivationTokenFromHash(raw.slice(hashIdx)) || raw
  } else if (raw.startsWith('token=') || raw.includes('token=')) {
    value = extractActivationTokenFromHash(`#${raw.includes('#') ? raw.split('#').pop() : raw}`) || raw
  }
  if (value.length < 32) {
    tokenError.value = 'Token inválido. Cole o token completo do deploy (mínimo 32 caracteres) ou o link com #token=…'
    return
  }
  token.value = value
  tokenDraft.value = ''
}

async function onSubmit(_event: FormSubmitEvent<Schema>) {
  if (!token.value || !available.value) return
  error.value = ''
  loading.value = true
  try {
    const res = await api.onboarding.complete({
      organization_name: state.organization_name!.trim(),
      email: state.email!.trim(),
      password: state.password!,
      password_confirmation: state.password_confirmation!,
      onboarding_token: token.value
    })
    token.value = null
    state.password = ''
    state.password_confirmation = ''
    await refreshIdentity()
    const target = res.data?.redirect || '/admin/offices/new'
    await navigateTo(target)
  } catch (caught) {
    error.value = apiErrorMessage(caught, 'Não foi possível concluir o onboarding.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="w-full min-w-0 space-y-6">
    <div class="space-y-1 text-center lg:hidden">
      <h1 class="text-xl font-semibold text-highlighted text-pretty">
        Configurar plataforma
      </h1>
      <p class="text-sm text-muted text-pretty">
        Crie o primeiro administrador global
      </p>
    </div>

    <UPageCard
      variant="subtle"
      class="w-full"
      :ui="{ container: 'sm:p-8 space-y-6' }"
      data-testid="onboarding-panel"
    >
      <div
        v-if="checking"
        class="space-y-3"
        role="status"
        aria-label="Verificando disponibilidade"
      >
        <USkeleton class="h-8 w-2/3" />
        <USkeleton class="h-10 w-full" />
        <USkeleton class="h-10 w-full" />
      </div>

      <UAlert
        v-else-if="trulyUnavailable"
        color="warning"
        icon="i-lucide-shield-off"
        title="Onboarding já concluído"
        description="Esta instalação já tem administrador. Use o login para entrar."
        data-testid="onboarding-blocked"
      >
        <template #actions>
          <UButton
            to="/login"
            color="neutral"
            variant="soft"
            label="Ir para o login"
            data-testid="onboarding-to-login"
          />
        </template>
      </UAlert>

      <div
        v-else-if="needsToken"
        class="space-y-4"
        data-testid="onboarding-token-step"
      >
        <div class="space-y-1">
          <h2 class="text-lg font-semibold text-highlighted">
            Token de deploy
          </h2>
          <p class="text-sm text-muted">
            A instalação está pronta para o primeiro admin. Cole o token gerado no deploy
            (ou o link completo com <code class="text-xs">#token=…</code>).
          </p>
        </div>

        <UAlert
          v-if="tokenError"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :title="tokenError"
          data-testid="onboarding-token-error"
        />

        <UFormField
          label="Token ou link de onboarding"
          name="deploy_token"
          required
        >
          <UTextarea
            v-model="tokenDraft"
            :rows="3"
            autoresize
            placeholder="Cole o token ou https://…/onboarding#token=…"
            data-testid="onboarding-token-input"
            class="w-full font-mono text-sm"
          />
        </UFormField>

        <UButton
          label="Continuar"
          color="primary"
          block
          size="lg"
          icon="i-lucide-arrow-right"
          data-testid="onboarding-token-continue"
          @click="applyTokenDraft"
        />
      </div>

      <template v-else>
        <div class="space-y-1">
          <h2 class="text-lg font-semibold text-highlighted">
            Primeiro administrador
          </h2>
          <p class="text-sm text-muted">
            Organização, e-mail e senha do PLATFORM_ADMIN. Em seguida você cria o escritório e conclui o onboarding fiscal.
          </p>
        </div>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :title="error"
          :close="{ onClick: () => { error = '' } }"
          data-testid="onboarding-error"
        />

        <UForm
          :schema="schema"
          :state="state"
          class="space-y-4"
          data-testid="onboarding-form"
          @submit="onSubmit"
        >
          <UFormField
            label="Nome da organização"
            name="organization_name"
            required
          >
            <UInput
              v-model="state.organization_name"
              autocomplete="organization"
              placeholder="Ex.: Contabilidade Horizonte"
              data-testid="onboarding-organization"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="E-mail do administrador"
            name="email"
            required
          >
            <UInput
              v-model="state.email"
              type="email"
              autocomplete="username"
              placeholder="admin@suaempresa.com.br"
              data-testid="onboarding-email"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Senha"
            name="password"
            required
          >
            <UInput
              v-model="state.password"
              type="password"
              autocomplete="new-password"
              data-testid="onboarding-password"
              class="w-full"
            />
          </UFormField>

          <UFormField
            label="Confirmar senha"
            name="password_confirmation"
            required
          >
            <UInput
              v-model="state.password_confirmation"
              type="password"
              autocomplete="new-password"
              data-testid="onboarding-password-confirm"
              class="w-full"
            />
          </UFormField>

          <UButton
            type="submit"
            label="Concluir e entrar"
            color="primary"
            block
            size="lg"
            :loading="loading"
            icon="i-lucide-shield-check"
            data-testid="onboarding-submit"
          />
        </UForm>
      </template>
    </UPageCard>
  </div>
</template>
