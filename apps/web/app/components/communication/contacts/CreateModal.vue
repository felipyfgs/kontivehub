<script setup lang="ts">
import type { ClientContact } from '~/types/api'
import { COMMUNICATION_CONTACT_SOLID_ACTION_CLASS } from '~/utils/communication-contacts'
import { apiErrorMessage } from '~/utils/api-error'

const open = defineModel<boolean>('open', { default: false })

const props = defineProps<{
  loading: boolean
  error: string | null
  canManage: boolean
}>()

const emit = defineEmits<{
  submit: [body: {
    name: string | null
    phone: string
    client_id?: number
    client_contact_id?: number
    is_primary?: boolean
    receives_automatic?: boolean
  }]
}>()

const api = useApi()

const name = ref('')
const phone = ref('')
const clientId = ref<number | null>(null)
const clientContactId = ref<number | null>(null)
const isPrimary = ref(false)
const receivesAutomatic = ref(true)
const clientContacts = ref<ClientContact[]>([])
const loadingClientContacts = ref(false)
const clientContactsError = ref<string | null>(null)
const validationError = ref<string | null>(null)
let clientContactsSequence = 0

const clientContactItems = computed(() => [
  { label: 'Nenhum', value: null },
  ...clientContacts.value
    .filter(item => item.is_active)
    .map(item => ({
      label: item.name,
      value: item.id
    }))
])

function reset() {
  name.value = ''
  phone.value = ''
  clientId.value = null
  clientContactId.value = null
  isPrimary.value = false
  receivesAutomatic.value = true
  clientContacts.value = []
  clientContactsError.value = null
  validationError.value = null
}

async function loadClientContacts(id: number) {
  const sequence = ++clientContactsSequence
  loadingClientContacts.value = true
  clientContactsError.value = null
  try {
    const res = await api.contacts.list(id)
    if (sequence !== clientContactsSequence || clientId.value !== id) return
    clientContacts.value = res.data
  } catch (caught) {
    if (sequence !== clientContactsSequence || clientId.value !== id) return
    clientContacts.value = []
    clientContactsError.value = apiErrorMessage(caught, 'Não foi possível carregar os contatos fiscais.')
  } finally {
    if (sequence === clientContactsSequence) loadingClientContacts.value = false
  }
}

function retryClientContacts(): void {
  if (clientId.value) void loadClientContacts(clientId.value)
}

watch(clientId, (id) => {
  ++clientContactsSequence
  clientContactId.value = null
  isPrimary.value = false
  receivesAutomatic.value = true
  if (id) void loadClientContacts(id)
  else {
    clientContacts.value = []
    clientContactsError.value = null
    loadingClientContacts.value = false
  }
})

function submit() {
  if (!props.canManage) return
  const normalizedPhone = phone.value.trim()
  if ((normalizedPhone.match(/\d/g) || []).length < 8) {
    validationError.value = 'Informe um telefone WhatsApp válido.'
    return
  }
  validationError.value = null
  emit('submit', {
    name: name.value.trim() || null,
    phone: normalizedPhone,
    ...(clientId.value
      ? {
          client_id: clientId.value,
          ...(clientContactId.value ? { client_contact_id: clientContactId.value } : {}),
          is_primary: isPrimary.value,
          receives_automatic: receivesAutomatic.value
        }
      : {})
  })
}

watch(open, (isOpen) => {
  if (!isOpen) reset()
})
</script>

<template>
  <ShellFormModal
    v-model:open="open"
    title="Novo contato"
    description="Informe o WhatsApp. O nome é opcional; sem nome o contato fica provisório."
    submit-label="Criar"
    :submit-class="COMMUNICATION_CONTACT_SOLID_ACTION_CLASS"
    :loading="loading"
    :disabled="!canManage || !phone.trim()"
    test-id="communication-contact-create-modal"
    @submit="submit"
    @cancel="reset"
  >
    <template #body>
      <div class="space-y-4">
        <UAlert
          v-if="validationError || error"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-x"
          :title="validationError || error || undefined"
        />
        <UFormField
          label="Nome"
          name="name"
        >
          <UInput
            v-model="name"
            placeholder="Opcional"
            autocomplete="name"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="WhatsApp"
          name="phone"
          required
        >
          <UInput
            v-model="phone"
            placeholder="Ex.: 11999998888"
            autocomplete="tel"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Cliente (opcional)"
          name="client_id"
          hint="Vincula a identidade ao cliente do escritório."
        >
          <FiscalClientPicker
            v-model="clientId"
            search-mode="select"
            placeholder="Selecionar cliente"
            class="w-full min-w-0"
          />
        </UFormField>
        <template v-if="clientId">
          <UAlert
            v-if="clientContactsError"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :title="clientContactsError"
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="outline"
                size="xs"
                label="Tentar novamente"
                :loading="loadingClientContacts"
                @click="retryClientContacts"
              />
            </template>
          </UAlert>
          <UFormField
            label="Contato fiscal (opcional)"
            name="client_contact_id"
          >
            <USelect
              v-model="clientContactId"
              :items="clientContactItems"
              value-key="value"
              placeholder="Nenhum"
              :loading="loadingClientContacts"
              :disabled="loadingClientContacts || !clientContacts.length"
              class="w-full"
              data-testid="communication-contact-create-client-contact"
            />
          </UFormField>
          <UFormField
            label="Opções de vínculo"
            name="link_options"
          >
            <div class="space-y-2">
              <UCheckbox
                v-model="isPrimary"
                label="Marcar como principal do cliente"
                data-testid="communication-contact-create-is-primary"
              />
              <UCheckbox
                v-model="receivesAutomatic"
                label="Recebe comunicações automáticas"
                data-testid="communication-contact-create-receives-automatic"
              />
            </div>
          </UFormField>
        </template>
      </div>
    </template>
  </ShellFormModal>
</template>
