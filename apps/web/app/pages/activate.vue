<script setup lang="ts">
/**
 * Ativação por link manual (#token= no fragmento).
 * Remove o fragmento imediatamente; token só no body da API.
 */
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { ActivationInspectResult } from '~/types/api'
import { consumeActivationTokenFromLocation } from '~/utils/activation'
import { apiErrorMessage } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

useSeoMeta({
  title: 'Ativar acesso · KontiveHub',
  description: 'Defina sua senha permanente para ativar o acesso'
})

const api = useApi()
const { refreshIdentity } = useSanctumAuth()

const token = ref<string | null>(null)
const inspecting = ref(true)
const inspect = ref<ActivationInspectResult | null>(null)
const error = ref('')
const loading = ref(false)

const schema = z.object({
  password: z.string('Informe a nova senha').min(8, 'Mínimo de 8 caracteres'),
  password_confirmation: z.string('Confirme a senha').min(1, 'Confirme a senha')
}).refine(d => d.password === d.password_confirmation, {
  message: 'As senhas não coincidem',
  path: ['password_confirmation']
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  password: '',
  password_confirmation: ''
})

onMounted(async () => {
  token.value = consumeActivationTokenFromLocation()
  if (!token.value) {
    inspecting.value = false
    inspect.value = { valid: false }
    return
  }
  try {
    const res = await api.activations.inspect(token.value)
    inspect.value = res.data
  } catch {
    inspect.value = { valid: false }
  } finally {
    inspecting.value = false
  }
})

async function onSubmit(_event: FormSubmitEvent<Schema>) {
  if (!token.value) return
  error.value = ''
  loading.value = true
  try {
    await api.activations.complete({
      token: token.value,
      password: state.password!,
      password_confirmation: state.password_confirmation!
    })
    // Não reexibe token; limpa estado local.
    token.value = null
    state.password = ''
    state.password_confirmation = ''
    await refreshIdentity()
    await navigateTo('/')
  } catch (caught) {
    error.value = apiErrorMessage(caught, 'Não foi possível ativar o acesso.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="w-full min-w-0 space-y-6">
    <div class="space-y-1 text-center lg:hidden">
      <h1 class="text-xl font-semibold text-highlighted text-pretty">
        Ativar acesso
      </h1>
      <p class="text-sm text-muted text-pretty">
        Defina sua senha permanente
      </p>
    </div>

    <UPageCard
      variant="subtle"
      class="w-full"
      :ui="{ container: 'sm:p-8 space-y-6' }"
      data-testid="activate-panel"
    >
      <div
        v-if="inspecting"
        class="space-y-3"
        role="status"
        aria-label="Verificando convite"
      >
        <USkeleton class="h-6 w-1/2" />
        <USkeleton class="h-4 w-2/3" />
        <USkeleton class="h-10 w-full" />
      </div>

      <template v-else-if="!inspect?.valid">
        <div class="space-y-2 text-center sm:text-left">
          <h2 class="text-lg font-semibold text-highlighted">
            Link inválido ou expirado
          </h2>
          <p class="text-sm text-muted">
            Solicite um novo acesso ao administrador.
          </p>
        </div>
        <UButton
          to="/login"
          label="Ir para o login"
          color="primary"
          block
          size="lg"
          data-testid="activate-invalid-login"
        />
      </template>

      <template v-else>
        <div class="space-y-1 text-center sm:text-left">
          <h2 class="text-lg font-semibold text-highlighted">
            Olá{{ inspect.invite_name ? `, ${inspect.invite_name}` : '' }}
          </h2>
          <p class="text-sm text-muted">
            Conta
            <span class="font-medium text-highlighted">{{ inspect.email_masked }}</span>
            · defina a senha permanente
          </p>
        </div>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :title="error"
          :close="{ onClick: () => { error = '' } }"
          data-testid="activate-error"
        />

        <UForm
          :schema="schema"
          :state="state"
          class="space-y-4"
          data-testid="activate-form"
          @submit="onSubmit"
        >
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
              data-testid="activate-password"
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
              data-testid="activate-password-confirmation"
            />
          </UFormField>
          <UButton
            type="submit"
            label="Ativar e entrar"
            color="primary"
            block
            size="lg"
            :loading="loading"
            data-testid="activate-submit"
          />
        </UForm>
      </template>
    </UPageCard>

    <p class="text-center text-xs text-muted text-pretty">
      Problemas de acesso? Fale com o administrador do escritório.
    </p>
  </div>
</template>
