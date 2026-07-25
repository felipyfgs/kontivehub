<script setup lang="ts">
/**
 * Primeiro acesso com senha provisória.
 * Mesmo destino pós-sucesso que /activate.
 */
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiErrorMessage } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

useSeoMeta({
  title: 'Primeiro acesso · KontiveHub',
  description: 'Troque a senha provisória e acesse o painel'
})

const api = useApi()
const { refreshIdentity } = useSanctumAuth()

const error = ref('')
const loading = ref(false)

const schema = z.object({
  email: z.email('Informe um e-mail válido'),
  temporary_password: z.string('Informe a senha provisória').min(1, 'Informe a senha provisória'),
  password: z.string('Informe a nova senha').min(8, 'Mínimo de 8 caracteres'),
  password_confirmation: z.string('Confirme a senha').min(1, 'Confirme a senha')
}).refine(d => d.password === d.password_confirmation, {
  message: 'As senhas não coincidem',
  path: ['password_confirmation']
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  email: '',
  temporary_password: '',
  password: '',
  password_confirmation: ''
})

async function onSubmit(_event: FormSubmitEvent<Schema>) {
  error.value = ''
  loading.value = true
  try {
    await api.activations.firstAccess({
      email: state.email!.trim(),
      temporary_password: state.temporary_password!,
      password: state.password!,
      password_confirmation: state.password_confirmation!
    })
    state.temporary_password = ''
    state.password = ''
    state.password_confirmation = ''
    await refreshIdentity()
    await navigateTo('/')
  } catch (caught) {
    error.value = apiErrorMessage(caught, 'Não foi possível concluir o primeiro acesso.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="w-full min-w-0 space-y-6">
    <div class="space-y-1 text-center lg:hidden">
      <h1 class="text-xl font-semibold text-highlighted text-pretty">
        Primeiro acesso
      </h1>
      <p class="text-sm text-muted text-pretty">
        Troque a senha provisória
      </p>
    </div>

    <UPageCard
      variant="subtle"
      class="w-full"
      :ui="{ container: 'sm:p-8 space-y-6' }"
      data-testid="first-access-panel"
    >
      <div class="space-y-1 text-center sm:text-left">
        <h2 class="text-lg font-semibold text-highlighted">
          Definir senha permanente
        </h2>
        <p class="text-sm text-muted">
          Use o e-mail e a senha provisória recebidos do administrador.
        </p>
      </div>

      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        :title="error"
        :close="{ onClick: () => { error = '' } }"
        data-testid="first-access-error"
      />

      <UForm
        :schema="schema"
        :state="state"
        class="space-y-4"
        data-testid="first-access-form"
        @submit="onSubmit"
      >
        <UFormField
          label="E-mail"
          name="email"
          required
        >
          <UInput
            v-model="state.email"
            type="email"
            autocomplete="username"
            class="w-full"
            data-testid="first-access-email"
          />
        </UFormField>
        <UFormField
          label="Senha provisória"
          name="temporary_password"
          required
        >
          <UInput
            v-model="state.temporary_password"
            type="password"
            autocomplete="current-password"
            class="w-full"
            data-testid="first-access-temporary"
          />
        </UFormField>
        <UFormField
          label="Nova senha"
          name="password"
          required
        >
          <UInput
            v-model="state.password"
            type="password"
            autocomplete="new-password"
            class="w-full"
            data-testid="first-access-password"
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
            class="w-full"
            data-testid="first-access-password-confirmation"
          />
        </UFormField>
        <UButton
          type="submit"
          label="Ativar e entrar"
          color="primary"
          block
          size="lg"
          :loading="loading"
          data-testid="first-access-submit"
        />
      </UForm>

      <p class="text-center text-xs text-muted text-pretty">
        <ULink
          to="/login"
          class="text-primary font-medium"
        >
          Já tem senha? Entrar
        </ULink>
      </p>
    </UPageCard>

    <p class="text-center text-xs text-muted text-pretty">
      Problemas de acesso? Fale com o administrador do escritório.
    </p>
  </div>
</template>
