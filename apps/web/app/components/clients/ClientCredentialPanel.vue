<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { ClientCredential, ClientCredentialSummary } from '~/types/api'

const props = withDefaults(defineProps<{
  clientId: number
  credential: ClientCredential | null
  /** Summary do show — permite OPERATOR/VIEWER ver status sem detalhe completo. */
  credentialSummary?: ClientCredentialSummary | null
  canManageCredentials: boolean
  /** false quando a page já fornece ShellSectionHeader. */
  showHeader?: boolean
}>(), {
  credentialSummary: null,
  showHeader: true
})

const emit = defineEmits<{
  activated: [credential: ClientCredential]
}>()

const open = ref(false)
const activating = ref(false)
const api = useApi()
const toast = useToast()
const credentialFile = ref<File | null>(null)
const fileInputKey = ref(0)

const hasCredential = computed(() => !!props.credential || !!props.credentialSummary)
const summaryValidTo = computed(() => props.credential?.valid_to || props.credentialSummary?.valid_to)
const summaryStatus = computed(() => props.credential?.status || props.credentialSummary?.status)

const schema = z.object({
  password: z.string().min(1, 'Informe a senha do certificado.')
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  password: ''
})

function submitCredentialForm() {
  const el = globalThis.document?.getElementById('client-credential-form') as HTMLFormElement | null
  el?.requestSubmit()
}

function clearSensitive() {
  state.password = ''
  credentialFile.value = null
  fileInputKey.value += 1
}

function selectCredentialFile(event: Event) {
  const input = event.target as HTMLInputElement
  credentialFile.value = input.files?.[0] || null
}

async function onSubmit(_event: FormSubmitEvent<Schema>) {
  if (!props.canManageCredentials) {
    return
  }

  if (!credentialFile.value) {
    toast.add({ title: 'Selecione um arquivo PFX.', color: 'warning' })
    return
  }

  activating.value = true
  try {
    const response = await api.credentials.activate(
      props.clientId,
      credentialFile.value,
      state.password || ''
    )
    open.value = false
    clearSensitive()
    toast.add({
      title: 'Certificado validado e ativado.',
      description: 'Somente metadados públicos ficam disponíveis na interface.',
      color: 'success'
    })
    emit('activated', response.data)
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Não foi possível ativar o certificado.'), color: 'error' })
  } finally {
    activating.value = false
  }
}

watch(open, (value) => {
  if (!value) {
    clearSensitive()
  }
})
</script>

<template>
  <div class="space-y-4">
    <ShellSectionHeader
      v-if="showHeader"
      title="Certificado A1"
      description="Um certificado por raiz do cliente. PFX e senha nunca são recuperáveis pela API."
    >
      <UButton
        v-if="canManageCredentials"
        :label="credential ? 'Substituir' : 'Enviar A1'"
        color="neutral"
        class="w-fit lg:ms-auto"
        icon="i-lucide-upload"
        @click="() => { open = true }"
      />
    </ShellSectionHeader>
    <div
      v-else-if="canManageCredentials"
      class="flex justify-end"
    >
      <UButton
        :label="credential ? 'Substituir' : 'Enviar A1'"
        color="neutral"
        class="w-fit"
        icon="i-lucide-upload"
        data-testid="client-credential-upload"
        @click="() => { open = true }"
      />
    </div>

    <UPageCard variant="subtle">
      <div v-if="!canManageCredentials" class="space-y-3">
        <template v-if="hasCredential">
          <ShellStatusBadge v-if="summaryStatus" :status="summaryStatus" />
          <dl class="space-y-2 text-sm">
            <div v-if="summaryValidTo">
              <dt class="text-muted">
                Validade
              </dt>
              <dd class="text-highlighted">
                {{ formatDateTime(summaryValidTo) }}
              </dd>
            </div>
          </dl>
          <UAlert
            color="info"
            icon="i-lucide-lock-keyhole"
            title="Gerenciado por ADMIN"
          />
        </template>
        <UAlert
          v-else
          color="info"
          icon="i-lucide-lock-keyhole"
          title="Gerenciado por ADMIN"
        />
      </div>
      <div v-else-if="credential" class="space-y-3 text-sm">
        <ShellStatusBadge :status="credential.status" />
        <dl class="space-y-2">
          <div>
            <dt class="text-muted">
              Titular
            </dt>
            <dd class="break-words text-highlighted">
              {{ credential.subject_name }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              CNPJ
            </dt>
            <dd class="font-mono text-highlighted">
              {{ credential.holder_cnpj }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Validade
            </dt>
            <dd class="text-highlighted">
              {{ formatDateTime(credential.valid_to) }}
            </dd>
          </div>
          <div>
            <dt class="text-muted">
              Fingerprint SHA-256
            </dt>
            <dd class="break-all font-mono text-xs text-highlighted">
              {{ credential.fingerprint_sha256 }}
            </dd>
          </div>
        </dl>
        <UAlert
          v-if="credential.expires_alert_30"
          color="warning"
          icon="i-lucide-badge-alert"
          title="Certificado próximo do vencimento"
        />
      </div>
      <UEmpty
        v-else
        icon="i-lucide-badge-alert"
        title="A1 não configurado"
        description="O upload valida senha, titular, raiz, validade e fingerprint antes da ativação."
      >
        <UButton label="Enviar e validar A1" @click="() => { open = true }" />
      </UEmpty>
    </UPageCard>

    <ShellFormModal
      v-if="canManageCredentials"
      v-model:open="open"
      title="Enviar certificado A1"
      description="O PFX e a senha são usados somente para validação e armazenados no cofre. Nunca poderão ser recuperados pela API."
      submit-label="Validar e ativar"
      :loading="activating"
      :show-default-footer="false"
      @cancel="() => { open = false }"
    >
      <template #body>
        <UForm
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
              id="credential-pfx"
              :key="fileInputKey"
              name="pfx"
              type="file"
              accept=".pfx,.p12,application/x-pkcs12"
              class="block w-full rounded-md border border-default bg-default px-3 py-2 text-sm"
              required
              @change="selectCredentialFile"
            >
          </UFormField>
          <UFormField label="Senha do certificado" name="password" required>
            <UInput
              v-model="state.password"
              type="password"
              autocomplete="off"
              class="w-full"
            />
          </UFormField>
          <UAlert
            color="warning"
            icon="i-lucide-shield-alert"
            title="Substituição atômica"
          />
        </UForm>
      </template>
      <template #footer>
        <ShellModalFooter
          submit-label="Validar e ativar"
          :loading="activating"
          @cancel="() => { open = false }"
          @submit="submitCredentialForm"
        />
      </template>
    </ShellFormModal>
  </div>
</template>
