<script setup lang="ts">
import type { Conversation } from '~/types/communication/conversations'
import {
  COMMUNICATION_CONVERSATION_STATUS,
  communicationDisplayName,
  formatCommunicationDate
} from '~/utils/communication'
import { communicationContactConversationsPath, communicationConversationPath } from '~/utils/communication-routes'

const props = withDefaults(defineProps<{
  contactId: number
  compact?: boolean
  excludeConversationId?: number | null
  limit?: number
}>(), {
  compact: false,
  excludeConversationId: null,
  limit: 10
})

const api = useApi()
const loading = ref(false)
const error = ref<string | null>(null)
const conversations = ref<Conversation[]>([])
const inboxes = ref<Record<number, string>>({})
let loadSequence = 0

const visibleConversations = computed(() => conversations.value
  .filter(conversation => conversation.id !== props.excludeConversationId)
  .slice(0, Math.max(1, props.limit)))

function requestLimit(): number {
  const presentationLimit = Math.max(1, Math.min(50, props.limit))
  return Math.min(100, presentationLimit + (props.excludeConversationId ? 1 : 0))
}

function requestIdentity(): string {
  return `${props.contactId}:${requestLimit()}`
}

async function load() {
  const sequence = ++loadSequence
  const contactId = props.contactId
  const identity = requestIdentity()
  loading.value = true
  error.value = null
  try {
    const [conversationResult, inboxResult] = await Promise.allSettled([
      api.communication.conversations.list({
        contact_id: contactId,
        page: 1,
        per_page: requestLimit(),
        sort_by: 'last_activity_desc'
      }),
      api.communication.inboxes.list()
    ])
    if (sequence !== loadSequence || identity !== requestIdentity()) return
    if (conversationResult.status === 'rejected') throw conversationResult.reason
    conversations.value = conversationResult.value.data
    inboxes.value = inboxResult.status === 'fulfilled'
      ? Object.fromEntries(inboxResult.value.data.map(inbox => [inbox.id, inbox.name]))
      : {}
  } catch {
    if (sequence !== loadSequence || identity !== requestIdentity()) return
    error.value = 'Não foi possível carregar as conversas deste contato.'
  } finally {
    if (sequence === loadSequence && identity === requestIdentity()) loading.value = false
  }
}

function conversationStatus(status: Conversation['status']) {
  return COMMUNICATION_CONVERSATION_STATUS[status] ?? {
    color: 'neutral' as const,
    label: status || 'Desconhecido'
  }
}

watch(() => [props.contactId, props.excludeConversationId, props.limit] as const, () => {
  void load()
}, { immediate: true })
</script>

<template>
  <section
    class="flex min-h-0 flex-col"
    :class="compact ? null : 'h-full'"
    :data-testid="compact
      ? 'communication-context-conversation-history'
      : 'communication-contact-conversations-tab'"
  >
    <div class="mb-3 flex shrink-0 items-center justify-between gap-2">
      <div>
        <h2 class="font-medium text-highlighted">
          {{ compact ? 'Histórico recente' : 'Conversas recentes' }}
        </h2>
        <p class="text-sm text-muted">
          {{ compact
            ? 'Outras conversas visíveis deste contato.'
            : 'As últimas conversas visíveis deste contato.' }}
        </p>
      </div>
      <UButton
        color="neutral"
        variant="ghost"
        :size="compact ? 'xs' : 'sm'"
        label="Ver todas"
        icon="i-lucide-arrow-up-right"
        :to="communicationContactConversationsPath(contactId)"
      />
    </div>
    <div
      v-if="loading"
      class="min-h-0 flex-1 space-y-2 overflow-y-auto"
      role="status"
      aria-label="Carregando conversas"
    >
      <USkeleton
        v-for="item in (compact ? Math.min(limit, 3) : 4)"
        :key="item"
        class="h-14 w-full"
      />
    </div>
    <ShellLoadError
      v-else-if="error"
      :title="error"
      class="min-h-0 flex-1"
      test-id="communication-contact-conversations-error"
      @retry="load"
    />
    <UEmpty
      v-else-if="!visibleConversations.length"
      icon="i-lucide-messages-square"
      :title="compact ? 'Nenhuma conversa anterior' : 'Ainda não há conversas'"
      :description="compact
        ? 'Este contato não possui outro atendimento visível.'
        : 'Quando houver atendimento para este contato, ele aparecerá aqui.'"
      class="flex-1"
      :class="compact ? 'min-h-32' : 'min-h-64'"
    />
    <ul
      v-else
      class="min-h-0 flex-1 divide-y divide-default overflow-y-auto rounded-md border border-default"
      :class="compact ? 'max-h-72' : null"
    >
      <li
        v-for="conversation in visibleConversations"
        :key="conversation.id"
        :data-testid="`communication-contact-conversation-${conversation.id}`"
      >
        <NuxtLink
          :to="communicationConversationPath(conversation.id)"
          class="block px-3 hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary"
          :class="compact ? 'py-2.5' : 'py-3'"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="truncate text-sm font-medium text-highlighted">
              {{ compact ? `Conversa #${conversation.id}` : communicationDisplayName(conversation) }}
            </span>
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
