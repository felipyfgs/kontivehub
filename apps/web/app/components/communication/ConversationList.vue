<script setup lang="ts">
import type { CommunicationConversation, CommunicationInbox } from '~/types/communication'
import {
  COMMUNICATION_CONVERSATION_STATUS,
  communicationConversationImageEvidence,
  communicationDisplayName,
  communicationListPhoneLine,
  communicationPreviewText,
  formatCommunicationDate
} from '~/utils/communication'

const props = defineProps<{
  conversations: CommunicationConversation[]
  inboxes: CommunicationInbox[]
  selectedId?: number | null
  openingId?: number | null
  loading?: boolean
  empty?: boolean
  hasMore?: boolean
  loadingMore?: boolean
  loadMoreError?: string | null
  total?: number
}>()

/** Altura única da linha: avatar + 3 faixas de texto/badge com folga para UBadge sm. */
const ROW_HEIGHT_CLASS = 'h-[5.75rem]'
const skeletonRows = Array.from({ length: 8 }, (_, index) => index)

const emit = defineEmits<{
  select: [conversation: CommunicationConversation]
  prefetch: [conversationId: number]
  loadMore: []
}>()

const conversationButtons = new Map<number, HTMLButtonElement>()
const showInboxName = computed(() => props.inboxes.length > 1)

function setConversationButton(conversationId: number, element: unknown): void {
  if (element instanceof HTMLButtonElement) {
    conversationButtons.set(conversationId, element)
    return
  }
  conversationButtons.delete(conversationId)
}

async function focusConversation(conversationId: number): Promise<boolean> {
  await nextTick()
  const button = conversationButtons.get(conversationId)
  if (!button?.isConnected) return false
  button.focus({ preventScroll: true })
  button.scrollIntoView({ block: 'nearest' })
  return document.activeElement === button
}

defineExpose({ focusConversation })

function inboxName(id: number): string {
  return props.inboxes.find(inbox => inbox.id === id)?.name || `Inbox #${id}`
}

function previewLine(conversation: CommunicationConversation): string {
  const preview = communicationPreviewText(conversation)
  if (preview) return preview
  if (communicationConversationImageEvidence(conversation)) return 'Imagem'
  return '—'
}

function isUnread(conversation: CommunicationConversation): boolean {
  return (conversation.unread_count ?? 0) > 0
}

function statusMeta(conversation: CommunicationConversation) {
  return COMMUNICATION_CONVERSATION_STATUS[conversation.status]
}

/** Status OPEN é o default da fila; só destaca os demais (e o estado “Abrindo”). */
function showStatusBadge(conversation: CommunicationConversation): boolean {
  return conversation.status !== 'OPEN' || props.openingId === conversation.id
}

function phoneLine(conversation: CommunicationConversation): string {
  return communicationListPhoneLine(conversation)
}
</script>

<template>
  <div
    data-testid="communication-conversation-list"
    class="min-h-0 flex-1 overflow-y-auto divide-y divide-default"
  >
    <div
      v-if="loading && !conversations.length"
      class="divide-y divide-default"
      role="status"
      aria-live="polite"
      aria-label="Carregando conversas"
      data-testid="communication-conversations-skeleton"
    >
      <div
        v-for="row in skeletonRows"
        :key="row"
        class="flex w-full shrink-0 items-center gap-3 border-l-2 border-transparent px-3 py-2.5"
        :class="ROW_HEIGHT_CLASS"
      >
        <USkeleton class="size-9 shrink-0 rounded-full" />
        <div class="flex min-w-0 flex-1 flex-col justify-center gap-1.5">
          <div class="flex items-center gap-2">
            <USkeleton class="h-4 w-32" />
            <USkeleton class="ms-auto h-3 w-12" />
          </div>
          <USkeleton class="h-3 w-28" />
          <div class="flex items-center gap-1.5">
            <USkeleton class="h-3 w-36" />
            <USkeleton class="h-5 w-14 rounded-md" />
          </div>
        </div>
      </div>
    </div>

    <button
      v-for="conversation in conversations"
      :id="`communication-conversation-${conversation.id}`"
      :key="conversation.id"
      :ref="element => setConversationButton(conversation.id, element)"
      :data-conversation-id="conversation.id"
      type="button"
      class="flex w-full shrink-0 items-center gap-3 overflow-hidden border-l-2 px-3 py-2.5 text-left transition-colors"
      :class="[
        ROW_HEIGHT_CLASS,
        selectedId === conversation.id
          ? 'border-primary bg-primary/10'
          : openingId === conversation.id
            ? 'border-muted bg-elevated/60'
            : 'border-transparent hover:border-primary hover:bg-primary/5'
      ]"
      :aria-current="selectedId === conversation.id ? 'true' : undefined"
      :aria-busy="openingId === conversation.id"
      @pointerenter="emit('prefetch', conversation.id)"
      @pointerdown="emit('prefetch', conversation.id)"
      @focus="emit('prefetch', conversation.id)"
      @click="emit('select', conversation)"
    >
      <UAvatar
        :alt="communicationDisplayName(conversation)"
        size="md"
        class="shrink-0"
      />

      <div class="flex min-h-0 min-w-0 flex-1 flex-col justify-center gap-1">
        <!-- Linha 1: nome + unread + horário -->
        <div class="flex min-w-0 items-center gap-2">
          <span
            class="min-w-0 flex-1 truncate text-sm leading-5 text-highlighted"
            :class="isUnread(conversation) ? 'font-semibold' : 'font-medium'"
          >
            {{ communicationDisplayName(conversation) }}
          </span>
          <UBadge
            v-if="isUnread(conversation)"
            data-testid="communication-conversation-unread"
            :label="String(conversation.unread_count)"
            color="primary"
            variant="solid"
            size="sm"
            class="min-w-5 shrink-0 justify-center px-1.5"
          />
          <span class="shrink-0 text-[11px] leading-4 tabular-nums text-muted">
            {{ formatCommunicationDate(conversation.last_message_at) }}
          </span>
        </div>

        <!-- Linha 2: telefone completo (sempre reserva altura) -->
        <p
          class="truncate text-[11px] leading-4 text-muted"
          data-testid="communication-conversation-secondary"
        >
          {{ phoneLine(conversation) }}
        </p>

        <!-- Linha 3: preview + chips (mesma faixa, sem 4ª linha que estourava) -->
        <div class="flex min-w-0 items-center gap-1.5">
          <p
            class="min-w-0 flex-1 truncate text-xs leading-4"
            :class="isUnread(conversation) ? 'font-medium text-highlighted' : 'text-toned'"
            data-testid="communication-conversation-preview"
          >
            {{ previewLine(conversation) }}
          </p>
          <span
            v-if="showInboxName"
            class="max-w-14 shrink-0 truncate text-[10px] text-dimmed"
          >
            {{ inboxName(conversation.inbox_id) }}
          </span>
          <UBadge
            v-if="openingId === conversation.id"
            label="Abrindo"
            color="neutral"
            variant="subtle"
            size="sm"
            class="shrink-0"
          />
          <UBadge
            v-else-if="showStatusBadge(conversation)"
            :label="statusMeta(conversation).label"
            :color="statusMeta(conversation).color"
            variant="subtle"
            size="sm"
            class="shrink-0"
          />
          <UBadge
            v-if="conversation.assignee_membership_id == null"
            label="Sem resp."
            color="warning"
            variant="soft"
            size="sm"
            class="shrink-0"
          />
          <UBadge
            v-if="conversation.priority > 0"
            :label="`P${conversation.priority}`"
            color="error"
            variant="soft"
            size="sm"
            class="shrink-0"
          />
          <UBadge
            v-for="label in conversation.labels?.slice(0, 1)"
            :key="label.id"
            :label="label.name"
            color="neutral"
            variant="outline"
            size="sm"
            class="max-w-16 shrink-0 truncate"
          />
        </div>
      </div>
    </button>

    <div
      v-if="conversations.length && (hasMore || loadingMore || loadMoreError)"
      class="space-y-2 border-t border-default p-2.5 text-center"
      data-testid="communication-conversations-pagination"
    >
      <UAlert
        v-if="loadMoreError"
        :title="loadMoreError"
        description="As conversas já carregadas permanecem disponíveis."
        color="warning"
        variant="subtle"
        :actions="[{
          label: 'Tentar novamente',
          color: 'neutral',
          variant: 'subtle',
          onClick: () => emit('loadMore')
        }]"
      />
      <UButton
        v-if="hasMore || loadingMore"
        label="Carregar mais"
        icon="i-lucide-chevron-down"
        color="neutral"
        variant="soft"
        size="sm"
        :loading="loadingMore"
        :disabled="loadingMore"
        :aria-label="loadingMore ? 'Carregando mais conversas' : 'Carregar mais conversas'"
        data-testid="communication-load-more"
        @click="emit('loadMore')"
      />
      <p
        v-if="total !== undefined"
        class="text-[11px] text-muted"
      >
        {{ conversations.length }} de {{ total }} conversas carregadas
      </p>
    </div>

    <div
      v-if="empty && !conversations.length"
      class="flex h-full min-h-64 flex-col items-center justify-center gap-3 p-6 text-center"
    >
      <UIcon
        name="i-lucide-message-circle-dashed"
        class="size-10 text-dimmed"
      />
      <div>
        <p class="text-sm font-medium text-highlighted">
          Nenhuma conversa encontrada
        </p>
        <p class="mt-1 text-xs text-muted">
          Ajuste os filtros ou aguarde uma nova mensagem.
        </p>
      </div>
    </div>
  </div>
</template>
