<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core'
import type { Contact } from '~/types/communication/contacts'
import type { Inbox } from '~/types/communication/inboxes'
import type { SendKind } from '~/types/communication/messages'
import { apiErrorMessage } from '~/utils/api-error'

const open = defineModel<boolean>('open', { default: false })
const props = defineProps<{
  contact: Contact | null
  contacts?: Contact[]
  inboxes: Inbox[]
  canReply: boolean
}>()
const emit = defineEmits<{ created: [conversationId: number] }>()
const api = useApi()
const toast = useToast()
const inboxId = ref<number | undefined>()
const contactId = ref<number | undefined>()
const identityId = ref<number | undefined>()
const body = ref('')
const file = ref<File | null>(null)
const ptt = ref(false)
const submitting = ref(false)
const error = ref<string | null>(null)
const capabilityLoading = ref(false)
const initiationEnabled = ref(false)
const initiationReason = ref<string | null>(null)
const idempotencyKey = ref('')
const lastAttemptFingerprint = ref<string | null>(null)
const contactSearch = ref('')
const contactResults = ref<Contact[]>([])
const knownContacts = ref<Contact[]>([])
const contactsPage = ref(1)
const contactsLastPage = ref(1)
const contactsLoading = ref(false)
const contactsError = ref<string | null>(null)
let contactsRequest = 0

const selectedContact = computed(() =>
  props.contact ?? knownContacts.value.find(contact => contact.id === contactId.value) ?? null
)
const contactItems = computed(() => {
  const items = contactResults.value.map(contact => ({
    label: contact.name?.trim() || `Contato #${contact.id}`,
    value: contact.id
  }))
  if (contactId.value && !items.some(item => item.value === contactId.value)) {
    const selected = knownContacts.value.find(contact => contact.id === contactId.value)
    if (selected) {
      items.unshift({
        label: selected.name?.trim() || `Contato #${selected.id}`,
        value: selected.id
      })
    }
  }
  return items
})
const identities = computed(() => (selectedContact.value?.identities ?? []).filter(identity => identity.is_active))
const availableInboxes = computed(() => props.inboxes.filter(inbox => inbox.is_enabled && inbox.status === 'CONNECTED'))
const inboxItems = computed(() => availableInboxes.value.map(inbox => ({ label: inbox.name, value: inbox.id })))
const selectedInbox = computed(() => availableInboxes.value.find(inbox => inbox.id === inboxId.value) ?? null)
const identityItems = computed(() => identities.value.map(identity => ({ label: identity.phone || identity.address_masked, value: identity.id })))
const valid = computed(() => props.canReply && initiationEnabled.value && selectedContact.value && selectedInbox.value && identityId.value && (body.value.trim() || file.value))
const contactsHasMore = computed(() => contactsPage.value < contactsLastPage.value)

const unavailableDescription = computed(() => {
  if (!props.canReply) return 'Seu perfil não possui a permissão communication.reply.'
  return {
    rollout_disabled: 'A iniciação de conversas ainda não foi liberada para este ambiente.',
    kill_switch_active: 'A iniciação de conversas está temporariamente pausada.',
    tenant_not_allowlisted: 'A iniciação de conversas ainda não foi liberada para este escritório.',
    gateway_unavailable: 'O gateway de comunicação está indisponível.',
    tenant_disabled: 'A comunicação deste escritório está desativada.',
    inbox_unavailable: 'Nenhuma inbox conectada está disponível para iniciar a conversa.',
    permission_denied: 'Seu perfil não pode iniciar conversas.'
  }[initiationReason.value || ''] || 'A iniciação de conversas está indisponível.'
})

function reset() {
  contactId.value = props.contact?.id
  inboxId.value = availableInboxes.value.find(inbox => inbox.is_default)?.id
    ?? availableInboxes.value[0]?.id
  identityId.value = identities.value.length === 1 ? identities.value[0]?.id : undefined
  body.value = ''
  file.value = null
  ptt.value = false
  error.value = null
  idempotencyKey.value = crypto.randomUUID()
  lastAttemptFingerprint.value = null
}

function mergeKnownContacts(incoming: Contact[]): void {
  const byId = new Map(knownContacts.value.map(contact => [contact.id, contact]))
  for (const contact of incoming) byId.set(contact.id, contact)
  knownContacts.value = [...byId.values()]
}

async function loadContacts(more = false): Promise<void> {
  if (props.contact) return
  const page = more ? contactsPage.value + 1 : 1
  const request = ++contactsRequest
  contactsLoading.value = true
  contactsError.value = null
  try {
    const response = await api.communication.contacts.list({
      page,
      per_page: 20,
      is_active: true,
      sort: 'name',
      sort_direction: 'asc',
      q: contactSearch.value.trim() || undefined
    })
    if (request !== contactsRequest) return
    contactResults.value = more ? [...contactResults.value, ...response.data] : response.data
    mergeKnownContacts(response.data)
    contactsPage.value = response.meta.current_page
    contactsLastPage.value = response.meta.last_page
  } catch (caught) {
    if (request !== contactsRequest) return
    contactsError.value = apiErrorMessage(caught, 'Não foi possível carregar os contatos.')
  } finally {
    if (request === contactsRequest) contactsLoading.value = false
  }
}

const searchContacts = useDebounceFn(() => {
  contactResults.value = []
  contactsPage.value = 1
  contactsLastPage.value = 1
  void loadContacts()
}, 250)

async function loadCapability() {
  capabilityLoading.value = true
  initiationEnabled.value = false
  initiationReason.value = null
  try {
    const response = await api.communication.catalog.outboundCapabilities()
    initiationEnabled.value = response.data.conversation_initiation.enabled
    initiationReason.value = response.data.conversation_initiation.reason
  } catch (caught) {
    initiationReason.value = null
    error.value = apiErrorMessage(caught, 'Não foi possível verificar a disponibilidade da iniciação.')
  } finally {
    capabilityLoading.value = false
  }
}

function kindForFile(input: File | null): SendKind | undefined {
  if (!input) return undefined
  if (input.type.startsWith('image/')) return 'IMAGE'
  if (input.type.startsWith('audio/')) return 'AUDIO'
  if (input.type.startsWith('video/')) return 'VIDEO'
  return 'DOCUMENT'
}

async function submit() {
  if (submitting.value || !valid.value || !selectedContact.value || !selectedInbox.value || !identityId.value) return
  const fingerprint = JSON.stringify({
    contactId: selectedContact.value.id,
    identityId: identityId.value,
    inboxId: selectedInbox.value.id,
    body: body.value,
    ptt: ptt.value,
    file: file.value
      ? [file.value.name, file.value.size, file.value.type, file.value.lastModified]
      : null
  })
  if (lastAttemptFingerprint.value !== null && lastAttemptFingerprint.value !== fingerprint) {
    idempotencyKey.value = crypto.randomUUID()
  }
  lastAttemptFingerprint.value = fingerprint
  submitting.value = true
  error.value = null
  try {
    const response = await api.communication.conversations.create({
      contact_id: selectedContact.value.id,
      identity_id: identityId.value,
      inbox_id: selectedInbox.value.id,
      body: body.value,
      file: file.value,
      kind: kindForFile(file.value),
      ptt: ptt.value
    }, idempotencyKey.value)
    open.value = false
    toast.add({ title: response.data.reused_conversation ? 'Conversa retomada.' : 'Conversa iniciada.', color: 'success' })
    emit('created', response.data.conversation.id)
  } catch (caught) {
    error.value = apiErrorMessage(caught, 'Não foi possível iniciar a conversa.')
  } finally {
    submitting.value = false
  }
}

watch(contactId, () => {
  identityId.value = identities.value.length === 1 ? identities.value[0]?.id : undefined
})
watch(availableInboxes, (inboxes) => {
  if (!open.value || inboxes.some(inbox => inbox.id === inboxId.value)) return
  inboxId.value = inboxes.find(inbox => inbox.is_default)?.id ?? inboxes[0]?.id
}, { deep: true })
watch(contactSearch, () => searchContacts())

function close() {
  open.value = false
}
watch(file, (value) => {
  if (!value?.type.startsWith('audio/')) ptt.value = false
})
watch(open, (value) => {
  if (!value) {
    contactsRequest++
    contactsLoading.value = false
    return
  }
  reset()
  mergeKnownContacts(props.contacts ?? [])
  contactSearch.value = ''
  contactResults.value = props.contacts ?? []
  contactsPage.value = 1
  contactsLastPage.value = 1
  if (!props.contact) void loadContacts()
  void loadCapability()
})
</script>

<template>
  <UModal v-model:open="open" title="Nova conversa" description="Escolha o destino e a inbox antes de enviar a primeira mensagem.">
    <template #body>
      <form class="space-y-4" @submit.prevent="submit">
        <UAlert
          v-if="!capabilityLoading && (!canReply || !initiationEnabled)"
          color="warning"
          variant="subtle"
          title="Nova conversa indisponível"
          :description="unavailableDescription"
        />
        <UFormField v-if="!contact" label="Contato">
          <UInput
            v-model="contactSearch"
            icon="i-lucide-search"
            placeholder="Buscar contato"
            :disabled="!canReply || capabilityLoading"
            class="mb-2 w-full"
            data-testid="communication-new-conversation-contact-search"
          />
          <USelectMenu
            v-model="contactId"
            :items="contactItems"
            value-key="value"
            placeholder="Selecione o contato"
            :disabled="!canReply || capabilityLoading"
            class="w-full"
          />
          <p v-if="contactsError" class="mt-1 text-xs text-error">
            {{ contactsError }}
          </p>
          <UButton
            v-else-if="contactsHasMore"
            size="xs"
            color="neutral"
            variant="link"
            label="Carregar mais contatos"
            :loading="contactsLoading"
            :disabled="!canReply || capabilityLoading"
            type="button"
            class="mt-1 px-0"
            data-testid="communication-new-conversation-load-more"
            @click="loadContacts(true)"
          />
        </UFormField>
        <UAlert
          v-if="!contact && !selectedContact"
          color="warning"
          variant="subtle"
          title="Selecione um contato antes de iniciar uma conversa."
        />
        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          :title="error"
        />
        <UFormField label="Identidade">
          <USelectMenu
            v-model="identityId"
            :items="identityItems"
            value-key="value"
            placeholder="Selecione a identidade"
            :disabled="!canReply || !initiationEnabled"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Inbox">
          <USelectMenu
            v-model="inboxId"
            :items="inboxItems"
            value-key="value"
            placeholder="Selecione a inbox"
            :disabled="!canReply || !initiationEnabled"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Mensagem">
          <UTextarea
            v-model="body"
            :disabled="!canReply || !initiationEnabled"
            placeholder="Escreva a primeira mensagem"
            :rows="4"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Anexo opcional">
          <input
            type="file"
            class="block w-full rounded-md border border-default bg-default px-3 py-2 text-sm text-toned file:mr-3 file:rounded-md file:border-0 file:bg-elevated file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-highlighted"
            accept="image/jpeg,image/png,image/webp,audio/ogg,audio/mpeg,audio/mp4,audio/webm,video/mp4,video/webm,application/pdf,text/plain,application/zip"
            :disabled="!canReply || !initiationEnabled"
            @change="file = ($event.target as HTMLInputElement).files?.[0] || null"
          >
          <p v-if="file" class="mt-1 text-xs text-muted">
            {{ file.name }}
          </p>
        </UFormField>
        <UCheckbox v-if="file?.type.startsWith('audio/')" v-model="ptt" label="Enviar como mensagem de voz" />
        <div class="flex justify-end gap-2">
          <UButton
            color="neutral"
            variant="outline"
            label="Cancelar"
            @click="close"
          /><UButton
            type="submit"
            label="Iniciar conversa"
            icon="i-lucide-send"
            :disabled="!valid || submitting"
            :loading="submitting || capabilityLoading"
          />
        </div>
      </form>
    </template>
  </UModal>
</template>
