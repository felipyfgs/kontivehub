<script setup lang="ts">
/**
 * Modal de criação de departamento — casca ShellFormModal.
 */
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { apiErrorMessage } from '~/utils/api-error'

const emit = defineEmits<{
  created: []
}>()

const api = useApi()
const toast = useToast()

const open = ref(false)
const creating = ref(false)

const schema = z.object({
  name: z.string().trim().min(2, 'Informe ao menos 2 caracteres'),
  code: z.string().trim().min(1, 'Informe a sigla').max(16, 'Sigla muito longa')
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  name: '',
  code: ''
})

function reset() {
  state.name = ''
  state.code = ''
}

watch(open, (isOpen) => {
  if (!isOpen) reset()
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  creating.value = true
  try {
    await api.work.departments.create({
      name: event.data.name.trim(),
      code: event.data.code.trim().toUpperCase()
    })
    toast.add({ title: 'Departamento criado.', color: 'success' })
    open.value = false
    reset()
    emit('created')
  } catch (e) {
    toast.add({ title: apiErrorMessage(e, 'Não foi possível criar.'), color: 'error' })
  } finally {
    creating.value = false
  }
}

function submitForm() {
  const el = document.getElementById('department-add-form') as HTMLFormElement | null
  el?.requestSubmit()
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Novo departamento"
    description="Área operacional do escritório (ex.: Fiscal, Contábil)."
    submit-label="Criar"
    :loading="creating"
    :show-default-footer="false"
    @cancel="() => { open = false }"
    @submit="submitForm"
  >
    <UButton
      label="Novo departamento"
      icon="i-lucide-plus"
      color="neutral"
      class="w-fit lg:ms-auto"
      data-testid="department-create-open"
    />

    <template #body>
      <UForm
        id="department-add-form"
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
            data-testid="department-name"
            placeholder="Ex.: Contábil"
            class="w-full"
            autofocus
          />
        </UFormField>
        <UFormField
          label="Sigla"
          name="code"
          required
          description="Código curto para filtros e relatórios."
        >
          <UInput
            v-model="state.code"
            data-testid="department-code"
            placeholder="Ex.: CTB"
            class="w-full uppercase"
            maxlength="16"
          />
        </UFormField>
      </UForm>
    </template>
    <template #footer>
      <ShellModalFooter
        submit-label="Criar"
        submit-test-id="department-create"
        :loading="creating"
        @cancel="() => { open = false }"
        @submit="submitForm"
      />
    </template>
  </ShellFormModal>
</template>
