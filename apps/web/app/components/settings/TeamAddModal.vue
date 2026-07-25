<script setup lang="ts">
/**
 * Modal de inclusão de membro.
 * Fonte: .local/reference/nuxt-dashboard-template/app/components/customers/AddModal.vue
 */
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { ActivationMethod, CredentialDeliveryPayload, OfficeRole } from '~/types/api'
import { apiErrorMessage } from '~/utils/api-error'

const emit = defineEmits<{
  created: [payload: CredentialDeliveryPayload]
}>()

const api = useApi()
const toast = useToast()

const open = ref(false)
const creating = ref(false)
const reconfirmPassword = ref('')

const schema = z.object({
  name: z.string().trim().min(2, 'Informe o nome'),
  email: z.email('Informe um e-mail válido'),
  role: z.enum(['ADMIN', 'OPERATOR', 'VIEWER']),
  method: z.enum(['MANUAL_LINK', 'TEMPORARY_PASSWORD'])
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  name: '',
  email: '',
  role: 'OPERATOR',
  method: 'MANUAL_LINK'
})

const roleItems = [
  { label: 'Admin', value: 'ADMIN' as OfficeRole },
  { label: 'Operador', value: 'OPERATOR' as OfficeRole },
  { label: 'Visualizador', value: 'VIEWER' as OfficeRole }
]

const methodItems = [
  { label: 'Link manual', value: 'MANUAL_LINK' as ActivationMethod },
  { label: 'Senha provisória', value: 'TEMPORARY_PASSWORD' as ActivationMethod }
]

function reset() {
  state.name = ''
  state.email = ''
  state.role = 'OPERATOR'
  state.method = 'MANUAL_LINK'
  reconfirmPassword.value = ''
}

watch(open, (isOpen) => {
  if (!isOpen) reset()
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (!reconfirmPassword.value) {
    toast.add({ title: 'Confirme sua senha para continuar.', color: 'warning' })
    return
  }
  creating.value = true
  try {
    await api.confirmPassword(reconfirmPassword.value)
    const res = await api.office.members.create({
      name: event.data.name.trim(),
      email: event.data.email.trim(),
      role: event.data.role,
      method: event.data.method
    })
    toast.add({ title: 'Membro criado.', color: 'success' })
    open.value = false
    reset()
    emit('created', res.data)
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Não foi possível criar o membro.'), color: 'error' })
  } finally {
    creating.value = false
  }
}

function submitForm() {
  const el = document.getElementById('team-add-form') as HTMLFormElement | null
  el?.requestSubmit()
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Novo membro"
    description="Define o papel e o método de primeiro acesso."
    submit-label="Criar"
    :loading="creating"
    :show-default-footer="false"
    @cancel="() => { open = false }"
    @submit="submitForm"
  >
    <UButton
      label="Novo membro"
      icon="i-lucide-user-plus"
      color="neutral"
      class="w-fit lg:ms-auto"
      data-testid="team-create-open"
    />

    <template #body>
      <UForm
        id="team-add-form"
        :schema="schema"
        :state="state"
        class="space-y-4"
        @submit="onSubmit"
      >
        <UFormField
          label="Nome"
          name="name"
          required
        >
          <UInput
            v-model="state.name"
            data-testid="team-member-name"
            class="w-full"
            autofocus
          />
        </UFormField>
        <UFormField
          label="E-mail"
          name="email"
          required
        >
          <UInput
            v-model="state.email"
            type="email"
            data-testid="team-member-email"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Papel"
          name="role"
          required
        >
          <USelect
            v-model="state.role"
            :items="roleItems"
            value-key="value"
            label-key="label"
            class="w-full"
            data-testid="team-member-role"
          />
        </UFormField>
        <UFormField
          label="Entrega"
          name="method"
          required
        >
          <USelect
            v-model="state.method"
            :items="methodItems"
            value-key="value"
            label-key="label"
            class="w-full"
            data-testid="team-member-method"
          />
        </UFormField>
        <UFormField
          label="Sua senha"
          name="reconfirm"
          required
          description="Confirmação para ação sensível."
        >
          <UInput
            v-model="reconfirmPassword"
            type="password"
            autocomplete="current-password"
            class="w-full"
            data-testid="team-member-reconfirm"
          />
        </UFormField>
      </UForm>
    </template>
    <template #footer>
      <ShellModalFooter
        submit-label="Criar"
        submit-test-id="team-create-submit"
        :loading="creating"
        @cancel="() => { open = false }"
        @submit="submitForm"
      />
    </template>
  </ShellFormModal>
</template>
