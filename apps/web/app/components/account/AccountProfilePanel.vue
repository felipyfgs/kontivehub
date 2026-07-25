<script setup lang="ts">
/** Perfil global do próprio usuário, independente do papel ou do Office atual. */
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiErrorMessage, apiFieldErrors } from '~/utils/api-error'

const api = useApi()
const toast = useToast()
const { refreshIdentity } = useSanctumAuth()
const { me } = useDashboard()

const schema = z.object({
  name: z.string().trim().min(2, 'Informe pelo menos 2 caracteres.').max(255),
  email: z.email('Informe um e-mail válido.'),
  current_password: z.string().min(1, 'Confirme sua senha.')
})

type ProfileSchema = z.output<typeof schema>

const state = reactive<Partial<ProfileSchema>>({
  name: '',
  email: '',
  current_password: ''
})
const fieldErrors = reactive({
  name: undefined as string | undefined,
  email: undefined as string | undefined,
  current_password: undefined as string | undefined
})
const saving = ref(false)

const roleLabel = computed(() => {
  if (me.value?.is_platform_admin) return 'Proprietário da plataforma'
  if (me.value?.role === 'ADMIN') return 'Administrador'
  if (me.value?.role === 'OPERATOR') return 'Operador'
  if (me.value?.role === 'VIEWER') return 'Visualizador'
  return 'Usuário'
})

watch(me, (identity) => {
  state.name = identity?.name || ''
  state.email = identity?.email || ''
}, { immediate: true })

function clearFieldErrors() {
  fieldErrors.name = undefined
  fieldErrors.email = undefined
  fieldErrors.current_password = undefined
}

async function save(event: FormSubmitEvent<ProfileSchema>) {
  clearFieldErrors()
  saving.value = true

  try {
    await api.confirmPassword(event.data.current_password)
    await api.account.update({
      name: event.data.name.trim(),
      email: event.data.email.trim()
    })
    state.current_password = ''
    await refreshIdentity()
    toast.add({ title: 'Perfil atualizado.', color: 'success' })
  } catch (caught) {
    const errors = apiFieldErrors(caught)
    for (const [name, messages] of Object.entries(errors)) {
      if (name === 'name' || name === 'email' || name === 'current_password') {
        fieldErrors[name] = messages[0]
      }
    }
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível atualizar o perfil.'),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UForm
    :schema="schema"
    :state="state"
    data-testid="account-profile-form"
    @submit="save"
  >
    <ShellSectionHeader
      title="Dados do perfil"
      description="Informações da sua conta de acesso."
      test-id="account-profile-header"
    >
      <UBadge
        color="neutral"
        variant="subtle"
        :label="roleLabel"
      />
      <UButton
        type="submit"
        label="Salvar alterações"
        :loading="saving"
        data-testid="account-profile-save"
      />
    </ShellSectionHeader>

    <ShellSectionCard>
      <UFormField
        name="name"
        label="Nome"
        description="Nome exibido no painel e nas trilhas administrativas."
        :error="fieldErrors.name"
        required
        class="flex max-sm:flex-col items-start justify-between gap-4"
      >
        <UInput
          v-model="state.name"
          autocomplete="name"
          class="w-full sm:max-w-sm"
          data-testid="account-profile-name"
        />
      </UFormField>

      <USeparator />

      <UFormField
        name="email"
        label="E-mail"
        description="Endereço usado para entrar na plataforma."
        :error="fieldErrors.email"
        required
        class="flex max-sm:flex-col items-start justify-between gap-4"
      >
        <UInput
          v-model="state.email"
          type="email"
          autocomplete="email"
          class="w-full sm:max-w-sm"
          data-testid="account-profile-email"
        />
      </UFormField>

      <USeparator />

      <UFormField
        name="current_password"
        label="Sua senha"
        description="Reconfirmação exigida para salvar alterações."
        :error="fieldErrors.current_password"
        required
        class="flex max-sm:flex-col items-start justify-between gap-4"
      >
        <UInput
          v-model="state.current_password"
          type="password"
          autocomplete="current-password"
          class="w-full sm:max-w-sm"
          data-testid="account-profile-password"
        />
      </UFormField>
    </ShellSectionCard>
  </UForm>
</template>
