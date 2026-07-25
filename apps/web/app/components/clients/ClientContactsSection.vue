<script setup lang="ts">
/**
 * Contatos internos do cliente — lista + modal CRUD.
 */
import type { Client, ClientContact } from '~/types/api'

const props = defineProps<{
  client: Client
  canManageClients: boolean
}>()

const emit = defineEmits<{
  updated: []
}>()

const api = useApi()
const toast = useToast()

const contactOpen = ref(false)
const contactSaving = ref(false)
const contactErrors = ref<Record<string, string[]>>({})
const editingContact = ref<ClientContact | null>(null)

const contactState = reactive({
  name: '',
  role: '',
  email: '',
  phone: '',
  is_whatsapp: false,
  is_primary: false,
  receives_alerts: false,
  notes: ''
})

const contacts = computed(() => props.client.contacts || [])

function submitContactForm() {
  const el = globalThis.document?.getElementById('client-contact-form') as HTMLFormElement | null
  el?.requestSubmit()
}

function openNewContact() {
  editingContact.value = null
  contactState.name = ''
  contactState.role = ''
  contactState.email = ''
  contactState.phone = ''
  contactState.is_whatsapp = false
  contactState.is_primary = false
  contactState.receives_alerts = false
  contactState.notes = ''
  contactErrors.value = {}
  contactOpen.value = true
}

function openEditContact(contact: ClientContact) {
  editingContact.value = contact
  contactState.name = contact.name
  contactState.role = contact.role || ''
  contactState.email = contact.email || ''
  contactState.phone = contact.phone || ''
  contactState.is_whatsapp = contact.is_whatsapp
  contactState.is_primary = contact.is_primary
  contactState.receives_alerts = contact.receives_alerts
  contactState.notes = contact.notes || ''
  contactErrors.value = {}
  contactOpen.value = true
}

async function onContactSubmit() {
  if (!props.canManageClients) return
  contactErrors.value = {}
  contactSaving.value = true
  try {
    const body = {
      name: contactState.name,
      role: contactState.role || null,
      email: contactState.email || null,
      phone: contactState.phone || null,
      is_whatsapp: contactState.is_whatsapp,
      is_primary: contactState.is_primary,
      receives_alerts: contactState.receives_alerts,
      notes: contactState.notes || null
    }
    if (editingContact.value) {
      await api.contacts.update(props.client.id, editingContact.value.id, body)
      toast.add({ title: 'Contato atualizado.', color: 'success' })
    } else {
      await api.contacts.create(props.client.id, body)
      toast.add({ title: 'Contato criado.', color: 'success' })
    }
    contactOpen.value = false
    emit('updated')
  } catch (caught) {
    contactErrors.value = apiFieldErrors(caught)
    toast.add({ title: apiErrorMessage(caught, 'Falha ao salvar contato.'), color: 'error' })
  } finally {
    contactSaving.value = false
  }
}

async function removeContact(contact: ClientContact) {
  try {
    await api.contacts.remove(props.client.id, contact.id)
    toast.add({ title: 'Contato removido.', color: 'success' })
    emit('updated')
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao remover contato.'), color: 'error' })
  }
}
</script>

<template>
  <div
    class="space-y-3"
    data-testid="client-contacts"
  >
    <div
      v-if="canManageClients"
      class="flex justify-end"
    >
      <UButton
        icon="i-lucide-plus"
        label="Adicionar contato"
        color="primary"
        variant="soft"
        class="w-fit"
        @click="openNewContact"
      />
    </div>

    <div
      v-if="contacts.length"
      class="divide-y divide-default"
    >
      <div
        v-for="contact in contacts"
        :key="contact.id"
        class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium">{{ contact.name }}</span>
            <UBadge
              v-if="contact.is_primary"
              color="primary"
              variant="subtle"
              icon="i-lucide-star"
            >
              Principal
            </UBadge>
          </div>
          <p class="text-sm text-muted">
            {{ contact.role || 'Sem função' }}
            <template v-if="contact.email">
              · {{ contact.email }}
            </template>
            <template v-if="contact.phone">
              · {{ contact.phone }}
            </template>
          </p>
        </div>
        <div
          v-if="canManageClients"
          class="flex gap-2"
        >
          <UButton
            size="sm"
            color="neutral"
            variant="ghost"
            icon="i-lucide-pencil"
            aria-label="Editar contato"
            @click="openEditContact(contact)"
          />
          <UButton
            size="sm"
            color="neutral"
            variant="ghost"
            icon="i-lucide-trash"
            aria-label="Remover contato"
            @click="removeContact(contact)"
          />
        </div>
      </div>
    </div>
    <UEmpty
      v-else
      icon="i-lucide-contact"
      title="Nenhum contato interno"
      description="Cadastre contatos operacionais do escritório."
    />

    <ShellFormModal
      v-if="canManageClients"
      v-model:open="contactOpen"
      :title="editingContact ? 'Editar contato' : 'Novo contato interno'"
      description="Contato operacional do escritório."
      submit-label="Salvar"
      :loading="contactSaving"
      :show-default-footer="false"
      @cancel="() => { contactOpen = false }"
    >
      <template #body>
        <form
          id="client-contact-form"
          class="space-y-4"
          @submit.prevent="onContactSubmit"
        >
          <UFormField
            label="Nome"
            name="name"
            required
            :error="contactErrors.name?.[0]"
          >
            <UInput
              v-model="contactState.name"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Função"
            name="role"
          >
            <UInput
              v-model="contactState.role"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="E-mail"
            name="email"
            :error="contactErrors.email?.[0]"
          >
            <UInput
              v-model="contactState.email"
              type="email"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Telefone"
            name="phone"
          >
            <UInput
              v-model="contactState.phone"
              class="w-full"
            />
          </UFormField>
          <UCheckbox
            v-model="contactState.is_whatsapp"
            label="WhatsApp"
          />
          <UCheckbox
            v-model="contactState.is_primary"
            label="Contato principal"
          />
          <UCheckbox
            v-model="contactState.receives_alerts"
            label="Recebe alertas (futuro)"
          />
          <UFormField
            label="Observações"
            name="notes"
          >
            <UTextarea
              v-model="contactState.notes"
              class="w-full"
              :rows="2"
            />
          </UFormField>
        </form>
      </template>
      <template #footer>
        <ShellModalFooter
          submit-label="Salvar"
          :loading="contactSaving"
          @cancel="() => { contactOpen = false }"
          @submit="submitContactForm"
        />
      </template>
    </ShellFormModal>
  </div>
</template>
