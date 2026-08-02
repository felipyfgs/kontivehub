<script setup lang="ts">
import { canReplyCommunication } from '~/utils/permissions'
import type { Contact } from '~/types/communication/contacts'
import { communicationConversationPath } from '~/utils/communication-routes'

const { sessionEpoch, me } = useDashboard()
const catalog = useCommunicationContactsCatalog()
const toast = useToast()
const selectedContact = ref<Contact | null>(null)
const newConversationOpen = ref(false)
let conversationRequestSequence = 0

async function saveContact(
  contact: Contact,
  body: { name: string | null, is_active: boolean },
  done: (ok: boolean) => void
) {
  done(await catalog.updateContact(contact, body))
}

async function openNewConversation(contact: Contact) {
  const sequence = ++conversationRequestSequence
  const epoch = sessionEpoch.value
  selectedContact.value = contact
  if (catalog.inboxesLoaded.value && !catalog.inboxesError.value) {
    newConversationOpen.value = true
    return
  }
  const loaded = await catalog.loadInboxes()
  if (
    sequence !== conversationRequestSequence
    || epoch !== sessionEpoch.value
    || selectedContact.value?.id !== contact.id
  ) return
  if (!loaded) {
    toast.add({
      title: catalog.inboxesError.value || 'Não foi possível carregar as inboxes disponíveis.',
      color: 'error'
    })
    return
  }
  newConversationOpen.value = true
}

watch(sessionEpoch, () => {
  ++conversationRequestSequence
  newConversationOpen.value = false
  selectedContact.value = null
})
</script>

<template>
  <ShellPagePanel
    id="communication-contacts"
    body-class="gap-0 overflow-hidden p-0 sm:p-0"
    data-testid="communication-contacts-panel"
  >
    <template #header>
      <ShellPageNavbar title="Contatos">
        <template #right>
          <CommunicationContactsCatalogToolbar
            class="hidden md:flex"
            :q="catalog.q.value"
            :inbox-id="catalog.inboxId.value"
            :inboxes="catalog.inboxes.value"
            :inboxes-loading="catalog.inboxesLoading.value"
            :inboxes-error="catalog.inboxesError.value"
            :definitions="catalog.filterDefinitions"
            :models="catalog.chipModels.value"
            :loading="catalog.loading.value"
            :reset-key="sessionEpoch"
            :sort="catalog.sort.value"
            :sort-direction="catalog.sortDirection.value"
            :can-manage="catalog.canManage.value"
            @update:q="catalog.onSearch"
            @update:inbox-id="catalog.setInboxId"
            @update:models="catalog.onStructuredFilters"
            @update:sorting="catalog.onSortingUpdate"
            @clear="catalog.clearFilters"
            @create="catalog.createOpen.value = true"
            @retry-inboxes="catalog.loadInboxes"
          />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar class="md:hidden">
        <CommunicationContactsCatalogToolbar
          :q="catalog.q.value"
          :inbox-id="catalog.inboxId.value"
          :inboxes="catalog.inboxes.value"
          :inboxes-loading="catalog.inboxesLoading.value"
          :inboxes-error="catalog.inboxesError.value"
          :definitions="catalog.filterDefinitions"
          :models="catalog.chipModels.value"
          :loading="catalog.loading.value"
          :reset-key="sessionEpoch"
          :sort="catalog.sort.value"
          :sort-direction="catalog.sortDirection.value"
          :can-manage="catalog.canManage.value"
          @update:q="catalog.onSearch"
          @update:inbox-id="catalog.setInboxId"
          @update:models="catalog.onStructuredFilters"
          @update:sorting="catalog.onSortingUpdate"
          @clear="catalog.clearFilters"
          @create="catalog.createOpen.value = true"
          @retry-inboxes="catalog.loadInboxes"
        />
      </UDashboardToolbar>
    </template>

    <template #body>
      <h1 data-testid="page-title" class="sr-only">
        Contatos de comunicação
      </h1>

      <CommunicationContactsCatalogTable
        :items="catalog.items.value"
        :loading="catalog.loading.value"
        :stale="catalog.stale.value"
        :error="catalog.loadError.value"
        :empty-kind="catalog.emptyKind.value"
        :page="catalog.page.value"
        :total="catalog.total.value"
        :per-page="catalog.perPage.value"
        :can-manage="catalog.canManage.value"
        :can-reply="canReplyCommunication(me)"
        :updating-id="catalog.updatingId.value"
        :update-error="catalog.updateError.value"
        @update:page="catalog.page.value = $event"
        @update:per-page="catalog.setPerPage"
        @open="catalog.openContact"
        @retry="catalog.load"
        @clear="catalog.clearFilters"
        @create="catalog.createOpen.value = true"
        @new-conversation="openNewConversation"
        @save="saveContact"
      />

      <CommunicationContactsCreateModal
        v-model:open="catalog.createOpen.value"
        :loading="catalog.creating.value"
        :error="catalog.createError.value"
        :can-manage="catalog.canManage.value"
        @submit="catalog.createContact"
      />
      <CommunicationNewConversationModal
        v-model:open="newConversationOpen"
        :contact="selectedContact"
        :inboxes="catalog.inboxes.value"
        :can-reply="canReplyCommunication(me)"
        @created="id => navigateTo(communicationConversationPath(id))"
      />
    </template>
  </ShellPagePanel>
</template>
