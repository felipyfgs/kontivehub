<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { ApiClient } from '~/composables/api/types'
import { apiErrorMessage } from '~/utils/api-error'

definePageMeta({ layout: 'auth' })

useSeoMeta({
  title: 'Redefinir senha · KontiveHub',
  description: 'Defina uma nova senha para sua conta KontiveHub'
})

const route = useRoute()
const client = useSanctumClient() as ApiClient

function queryString(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

const token = ref(queryString(route.query.token))
const email = ref(queryString(route.query.email))
const error = ref('')
const loading = ref(false)
const completed = ref(false)
const validLink = computed(() => token.value.length > 0 && email.value.length > 0)

const schema = z.object({
  password: z.string('Informe a nova senha').min(8, 'Mínimo de 8 caracteres'),
  password_confirmation: z.string('Confirme a senha').min(1, 'Confirme a senha')
}).refine(data => data.password === data.password_confirmation, {
  message: 'As senhas não coincidem',
  path: ['password_confirmation']
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  password: '',
  password_confirmation: ''
})

onMounted(() => {
  if (token.value || email.value) {
    window.history.replaceState(null, '', '/reset-password')
  }
})

async function onSubmit(_event: FormSubmitEvent<Schema>) {
  if (!validLink.value) return

  error.value = ''
  loading.value = true

  try {
    await client('/reset-password', {
      method: 'POST',
      body: {
        token: token.value,
        email: email.value,
        password: state.password,
        password_confirmation: state.password_confirmation
      }
    })

    token.value = ''
    email.value = ''
    state.password = ''
    state.password_confirmation = ''
    completed.value = true
  } catch (caught) {
    error.value = apiErrorMessage(caught, 'O link é inválido, expirou ou já foi utilizado.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="w-full min-w-0 space-y-6">
    <div class="space-y-1 text-center lg:hidden">
      <h1 class="text-xl font-semibold text-highlighted text-pretty">
        Redefinir senha
      </h1>
      <p class="text-sm text-muted text-pretty">
        Proteja seu acesso ao KontiveHub
      </p>
    </div>

    <UPageCard
      variant="subtle"
      class="w-full"
      :ui="{ container: 'sm:p-8 space-y-6' }"
      data-testid="reset-password-panel"
    >
      <template v-if="completed">
        <div class="space-y-2 text-center sm:text-left">
          <h2 class="text-lg font-semibold text-highlighted">
            Senha redefinida
          </h2>
          <p class="text-sm text-muted">
            Sua nova senha já pode ser usada para entrar no KontiveHub.
          </p>
        </div>
        <UButton
          to="/login"
          label="Ir para o login"
          color="primary"
          block
          size="lg"
          data-testid="reset-password-login"
        />
      </template>

      <template v-else-if="!validLink">
        <div class="space-y-2 text-center sm:text-left">
          <h2 class="text-lg font-semibold text-highlighted">
            Link inválido
          </h2>
          <p class="text-sm text-muted">
            Solicite uma nova redefinição de senha e use o link mais recente.
          </p>
        </div>
        <UButton
          to="/login"
          label="Voltar ao login"
          color="primary"
          block
          size="lg"
          data-testid="reset-password-invalid-login"
        />
      </template>

      <template v-else>
        <div class="space-y-1 text-center sm:text-left">
          <h2 class="text-lg font-semibold text-highlighted">
            Defina uma nova senha
          </h2>
          <p class="text-sm text-muted">
            Escolha uma senha segura para continuar usando sua conta.
          </p>
        </div>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          :title="error"
          :close="{ onClick: () => { error = '' } }"
          data-testid="reset-password-error"
        />

        <UForm
          :schema="schema"
          :state="state"
          class="space-y-4"
          data-testid="reset-password-form"
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
              data-testid="reset-password-value"
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
              data-testid="reset-password-confirmation"
            />
          </UFormField>
          <UButton
            type="submit"
            label="Redefinir senha"
            color="primary"
            block
            size="lg"
            :loading="loading"
            data-testid="reset-password-submit"
          />
        </UForm>
      </template>
    </UPageCard>
  </div>
</template>
