<script setup lang="ts">
import type { CommunicationConversation } from '~/types/communication'
import {
  COMMUNICATION_CONVERSATION_STATUS,
  communicationDisplayName,
  formatCommunicationDate
} from '~/utils/communication'
import { communicationConversationPath } from '~/utils/communication-routes'

const props = defineProps<{
  contactId: number
}>()

const api = useApi()
const loading = ref(false)
const error = ref<string | null>(null)
const conversations = ref<CommunicationConversation[]>([])
const inboxes = ref<Record<number, string>>({})
let loadSequence = 0

async function load() {
  const sequence = ++loadSequence
  const contactId = props.contactId
  loading.value = true
  error.value = null
  try {
    const [conversationResult, inboxResult] = await Promise.allSettled([
      api.communication.conversations.list({
        contact_id: contactId,
        page: 1,
        per_page: 10,
        sort_by: 'last_activity_desc'
      }),
      api.communication.inboxes.list()
    ])
    if (sequence !== loadSequence || contactId !== props.contactId) return
    if (conversationResult.status === 'rejected') throw conversationResult.reason
    conversations.value = conversationResult.value.data
    inboxes.value = inboxResult.status === 'fulfilled'
      ? Object.fromEntries(inboxResult.value.data.map(inbox => [inbox.id, inbox.name]))
      : {}
  } catch {
    if (sequence !== loadSequence || contactId !== props.contactId) return
    error.value = 'Não foi possível carregar as conversas deste contato.'
  } finally {
    if (sequence === loadSequence && contactId === props.contactId) loading.value = false
  }
}

function conversationStatus(status: CommunicationConversation['status']) {
  return COMMUNICATION_CONVERSATION_STATUS[status] ?? {
    color: 'neutral' as const,
    label: status || 'Desconhecido'
  }
}

watch(() => props.contactId, () => {
  void load()
}, { immediate: true })
</script>

<template>
  <section class="flex h-full min-h-0 flex-col" data-testid="communication-contact-conversations-tab">
    <div class="mb-3 flex shrink-0 items-center justify-between gap-2">
      <div>
        <h2 class="font-medium text-highlighted">
          Conversas recentes
        </h2>
        <p class="text-sm text-muted">
          As dez últimas conversas visíveis deste contato.
        </p>
      </div>
      <UButton
        color="neutral"
        variant="ghost"
        size="sm"
        label="Ver todas"
        icon="i-lucide-arrow-up-right"
        :to="{ path: '/communication', query: { contact_id: String(contactId) } }"
      />
    </div>
    <div
      v-if="loading"
      class="min-h-0 flex-1 space-y-2 overflow-y-auto"
      role="status"
      aria-label="Carregando conversas"
    >
      <USkeleton v-for="item in 4" :key="item" class="h-14 w-full" />
    </div>
    <ShellLoadError
      v-else-if="error"
      :title="error"
      class="min-h-0 flex-1"
      test-id="communication-contact-conversations-error"
      @retry="load"
    />
    <UEmpty
      v-else-if="!conversations.length"
      icon="i-lucide-messages-square"
      title="Ainda não há conversas"
      description="Quando houver atendimento para este contato, ele aparecerá aqui."
      class="min-h-64 flex-1"
    />
    <ul v-else class="min-h-0 flex-1 divide-y divide-default overflow-y-auto rounded-md border border-default">
      <li v-for="conversation in conversations" :key="conversation.id">
        <NuxtLink
          :to="communicationConversationPath(conversation.id)"
          class="block px-3 py-3 hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="truncate text-sm font-medium text-highlighted">{{ communicationDisplayName(conversation) }}</span>
            <UBadge
              size="sm"
              variant="subtle"
              :color="conversationStatus(conversation.status).color"
              :label="conversationStatus(conversation.status).label"
            />
          </div>
          <p v-if="conversation.preview?.text" class="mt-1 truncate text-xs text-muted">{{ conversation.preview.text }}</p>
          <p class="mt-1 flex justify-between gap-2 text-xs text-muted">
            <span>{{ inboxes[conversation.inbox_id] || `Inbox #${conversation.inbox_id}` }}</span>
            <time v-if="conversation.last_message_at" :datetime="conversation.last_message_at">
              {{ formatCommunicationDate(conversation.last_message_at) }}
            </time>
          </p>
        </NuxtLink>
      </li>
    </ul>
  </section>
</template>
