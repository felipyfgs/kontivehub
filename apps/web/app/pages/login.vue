<script setup lang="ts">
import * as z from 'zod'
import type { AuthFormField, FormSubmitEvent } from '@nuxt/ui'
import type { MeIdentity } from '~/utils/permissions'
import { homeForIdentity } from '~/utils/auth-redirect'
import { consumeAuthReturn } from '~/utils/auth-return'

/**
 * Login no padrão oficial Nuxt UI:
 * https://ui.nuxt.com/docs/components/auth-form
 * (UPageCard + UAuthForm + schema Zod)
 */
definePageMeta({ layout: 'auth' })

useSeoMeta({
  title: 'Entrar · KontiveHub',
  description: 'Acesso seguro à gestão fiscal do escritório'
})

const { loginWithFreshCsrf, refreshIdentity, user } = useFreshSanctumAuth<MeIdentity>()
const error = ref('')
const loading = ref(false)

const schema = z.object({
  email: z.email('Informe um e-mail válido'),
  // Zod 4: mensagem no type-check evita "Invalid input: expected string…" em inglês
  password: z.string('Informe a senha').min(1, 'Informe a senha')
})

type Schema = z.output<typeof schema>

const fields: AuthFormField[] = [{
  name: 'email',
  type: 'email',
  label: 'E-mail',
  placeholder: 'voce@escritorio.com.br',
  required: true,
  autocomplete: 'username'
}, {
  name: 'password',
  type: 'password',
  label: 'Senha',
  placeholder: 'Sua senha',
  required: true,
  autocomplete: 'current-password'
}]

async function onSubmit(event: FormSubmitEvent<Schema>) {
  error.value = ''
  loading.value = true
  try {
    await loginWithFreshCsrf({
      email: event.data.email,
      password: event.data.password
    })

    await refreshIdentity()
    const identity = unwrapMeUser(user.value as MeIdentity)
    const redirect = consumeAuthReturn(identity)
    await navigateTo(redirect || homeForIdentity(identity))
  } catch (caught) {
    error.value = apiErrorMessage(caught, 'Credenciais inválidas ou sessão não iniciada.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="w-full min-w-0 space-y-6">
    <div class="space-y-1 text-center lg:hidden">
      <h1 class="text-xl font-semibold text-highlighted text-pretty">
        Entrar no painel
      </h1>
      <p class="text-sm text-muted text-pretty">
        Só equipe do escritório
      </p>
    </div>

    <UPageCard
      variant="subtle"
      class="w-full"
      :ui="{
        container: 'sm:p-8 space-y-6'
      }"
      data-testid="login-panel"
    >
      <UAuthForm
        :schema="schema"
        :fields="fields"
        :loading="loading"
        title="Bem-vindo de volta"
        description="Use o e-mail e a senha do escritório."
        icon="i-lucide-lock-keyhole"
        :submit="{
          label: 'Entrar',
          color: 'primary',
          block: true,
          size: 'lg',
          loading
        }"
        data-testid="login-form"
        @submit="onSubmit"
      >
        <template #validation>
          <UAlert
            v-if="error"
            color="error"
            variant="subtle"
            icon="i-lucide-circle-alert"
            :title="error"
            :close="{ onClick: () => { error = '' } }"
            data-testid="login-error"
          />
        </template>

        <template #footer>
          <p class="text-center text-xs text-muted text-pretty">
            Ambiente protegido · não compartilhe credenciais · sessões protegidas por CSRF
          </p>
        </template>
      </UAuthForm>
    </UPageCard>

    <p class="text-center text-xs text-muted text-pretty">
      Problemas de acesso? Fale com o administrador do escritório.
    </p>
  </div>
</template>
