<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { ClientCredential } from '~/types/api'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  clientId: number | null
  clientLabel?: string | null
  canManageCredentials: boolean
}>()

const emit = defineEmits<{
  saved: []
}>()

const api = useApi()
const toast = useToast()

const loading = ref(false)
const activating = ref(false)
const credential = ref<ClientCredential | null>(null)
const credentialFile = ref<File | null>(null)
const fileInputKey = ref(0)

const schema = z.object({
  password: z.string().min(1, 'Informe a senha do certificado.')
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  password: ''
})

const title = computed(() =>
  credential.value ? 'Atualizar certificado A1' : 'Enviar certificado A1'
)

const description = computed(() => {
  const name = props.clientLabel?.trim()
  return name
    ? name
    : 'PFX e senha são usados na validação e ficam no cofre.'
})

function clearSensitive() {
  state.password = ''
  credentialFile.value = null
  fileInputKey.value += 1
}

function selectCredentialFile(event: Event) {
  const input = event.target as HTMLInputElement
  credentialFile.value = input.files?.[0] || null
}

async function loadCredential() {
  if (!props.clientId || !props.canManageCredentials) {
    credential.value = null
    return
  }
  loading.value = true
  try {
    credential.value = (await api.credentials.get(props.clientId)).data
  } catch {
    credential.value = null
  } finally {
    loading.value = false
  }
}

async function onSubmit(_event: FormSubmitEvent<Schema>) {
  if (!props.canManageCredentials || !props.clientId) {
    return
  }
  if (!credentialFile.value) {
    toast.add({ title: 'Selecione um arquivo PFX.', color: 'warning' })
    return
  }

  activating.value = true
  try {
    await api.credentials.activate(
      props.clientId,
      credentialFile.value,
      state.password || ''
    )
    open.value = false
    clearSensitive()
    toast.add({
      title: 'Certificado validado e ativado.',
      color: 'success'
    })
    emit('saved')
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível ativar o certificado.'),
      color: 'error'
    })
  } finally {
    activating.value = false
  }
}

watch(open, async (value) => {
  if (value) {
    clearSensitive()
    await loadCredential()
  } else {
    clearSensitive()
    credential.value = null
  }
})

function submitForm() {
  const el = document.getElementById('client-credential-form') as HTMLFormElement | null
  el?.requestSubmit()
}
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    :title="title"
    :description="description"
    :submit-label="credential ? 'Atualizar A1' : 'Validar e ativar'"
    :loading="activating"
    :show-default-footer="canManageCredentials && !loading"
    :disabled="loading"
    @cancel="() => { open = false }"
    @submit="submitForm"
  >
    <template #body>
      <div class="space-y-4">
        <ShellLoadingModalBody v-if="loading" />

        <div
          v-else-if="credential"
          class="rounded-lg bg-elevated/50 px-3 py-2 text-sm space-y-1"
        >
          <p class="font-medium text-highlighted truncate">
            {{ credential.subject_name || 'A1 ativo' }}
          </p>
          <p class="text-muted tabular-nums">
            Válido até {{ formatDateTime(credential.valid_to) }}
          </p>
          <p
            v-if="credential.holder_cnpj"
            class="font-mono text-xs text-muted"
          >
            {{ formatCnpj(credential.holder_cnpj) }}
          </p>
        </div>

        <UAlert
          v-if="!canManageCredentials"
          color="info"
          icon="i-lucide-lock-keyhole"
          title="Somente administradores"
        />

        <UForm
          v-else-if="!loading"
          id="client-credential-form"
          :schema="schema"
          :state="state"
          class="space-y-4"
          @submit="onSubmit"
        >
          <UFormField
            label="Arquivo PFX"
            name="pfx"
            required
            help="Máximo de 5 MB. Formatos .pfx ou .p12."
          >
            <input
              :id="`credential-pfx-modal-${clientId || 'x'}`"
              :key="fileInputKey"
              name="pfx"
              type="file"
              accept=".pfx,.p12,application/x-pkcs12"
              class="block w-full rounded-md border border-default bg-default px-3 py-2 text-sm"
              required
              @change="selectCredentialFile"
            >
          </UFormField>
          <UFormField
            label="Senha do certificado"
            name="password"
            required
          >
            <UInput
              v-model="state.password"
              type="password"
              autocomplete="off"
              class="w-full"
            />
          </UFormField>
        </UForm>
      </div>
    </template>
  </ShellFormModal>
</template>
