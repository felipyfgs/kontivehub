<script setup lang="ts">
import { canReplyCommunication } from '~/utils/permissions'
import type { CommunicationContact, CommunicationInbox } from '~/types/communication'
import { apiErrorMessage } from '~/utils/api-error'
import { communicationConversationPath } from '~/utils/communication-routes'

const { sessionEpoch, me } = useDashboard()
const catalog = useCommunicationContactsCatalog()
const api = useApi()
const toast = useToast()
const selectedContact = ref<CommunicationContact | null>(null)
const newConversationOpen = ref(false)
const conversationInboxes = ref<CommunicationInbox[]>([])
let conversationRequestSequence = 0

async function saveContact(
  contact: CommunicationContact,
  body: { name: string | null, is_active: boolean },
  done: (ok: boolean) => void
) {
  done(await catalog.updateContact(contact, body))
}

async function openNewConversation(contact: CommunicationContact) {
  const sequence = ++conversationRequestSequence
  const epoch = sessionEpoch.value
  selectedContact.value = contact
  if (conversationInboxes.value.length) {
    newConversationOpen.value = true
    return
  }
  try {
    const inboxes = (await api.communication.inboxes.list()).data
    if (
      sequence !== conversationRequestSequence
      || epoch !== sessionEpoch.value
      || selectedContact.value?.id !== contact.id
    ) return
    conversationInboxes.value = inboxes
    newConversationOpen.value = true
  } catch (caught) {
    if (sequence !== conversationRequestSequence || epoch !== sessionEpoch.value) return
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível carregar as inboxes disponíveis.'),
      color: 'error'
    })
  }
}

watch(sessionEpoch, () => {
  ++conversationRequestSequence
  newConversationOpen.value = false
  selectedContact.value = null
  conversationInboxes.value = []
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
            :definitions="catalog.filterDefinitions"
            :models="catalog.chipModels.value"
            :loading="catalog.loading.value"
            :reset-key="sessionEpoch"
            :sort="catalog.sort.value"
            :sort-direction="catalog.sortDirection.value"
            :can-manage="catalog.canManage.value"
            @update:q="catalog.onSearch"
            @update:models="catalog.onStructuredFilters"
            @update:sorting="catalog.onSortingUpdate"
            @clear="catalog.clearFilters"
            @create="catalog.createOpen.value = true"
          />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar class="md:hidden">
        <CommunicationContactsCatalogToolbar
          :q="catalog.q.value"
          :definitions="catalog.filterDefinitions"
          :models="catalog.chipModels.value"
          :loading="catalog.loading.value"
          :reset-key="sessionEpoch"
          :sort="catalog.sort.value"
          :sort-direction="catalog.sortDirection.value"
          :can-manage="catalog.canManage.value"
          @update:q="catalog.onSearch"
          @update:models="catalog.onStructuredFilters"
          @update:sorting="catalog.onSortingUpdate"
          @clear="catalog.clearFilters"
          @create="catalog.createOpen.value = true"
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
        :inboxes="conversationInboxes"
        :can-reply="canReplyCommunication(me)"
        @created="id => navigateTo(communicationConversationPath(id))"
      />
    </template>
  </ShellPagePanel>
</template>
