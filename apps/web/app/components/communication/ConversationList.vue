<script setup lang="ts">
import type { CommunicationConversation, CommunicationInbox } from '~/types/communication'
import type { CommunicationConversationImageEvidence } from '~/utils/communication'
import {
  COMMUNICATION_CONVERSATION_STATUS,
  communicationContactLabel,
  communicationConversationImageEvidence,
  communicationDisplayName,
  formatCommunicationDate
} from '~/utils/communication'

const props = defineProps<{
  conversations: CommunicationConversation[]
  inboxes: CommunicationInbox[]
  selectedId?: number | null
  openingId?: number | null
  loading?: boolean
  refreshing?: boolean
  empty?: boolean
  hasMore?: boolean
  loadingMore?: boolean
  loadMoreError?: string | null
  total?: number
}>()

const emit = defineEmits<{
  select: [conversation: CommunicationConversation]
  prefetch: [conversationId: number]
  loadMore: []
}>()

const conversationRows = computed(() => props.conversations.map(conversation => ({
  conversation,
  imageEvidence: communicationConversationImageEvidence(conversation)
})))
const failedImagePreviewIds = ref(new Set<number>())
const conversationButtons = new Map<number, HTMLButtonElement>()

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

function imagePreviewAvailable(evidence: CommunicationConversationImageEvidence): boolean {
  return Boolean(evidence.previewUrl) && !failedImagePreviewIds.value.has(evidence.messageId)
}

function markImagePreviewUnavailable(messageId: number): void {
  failedImagePreviewIds.value = new Set([...failedImagePreviewIds.value, messageId])
}

function inboxName(id: number): string {
  return props.inboxes.find(inbox => inbox.id === id)?.name || `Inbox #${id}`
}
</script>

<template>
  <div
    data-testid="communication-conversation-list"
    class="min-h-0 flex-1 overflow-y-auto divide-y divide-default"
  >
    <div
      v-if="refreshing && conversations.length"
      class="sticky top-0 z-10 flex items-center justify-center gap-2 border-b border-default bg-default/95 px-3 py-2 text-xs text-muted backdrop-blur"
      role="status"
      aria-live="polite"
      data-testid="communication-conversations-refreshing"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="size-4 animate-spin"
        aria-hidden="true"
      />
      Atualizando conversas
    </div>

    <div
      v-if="loading && !conversations.length"
      class="flex h-full min-h-64 flex-col items-center justify-center gap-3 p-8 text-center"
      role="status"
      aria-live="polite"
    >
      <UIcon
        name="i-lucide-message-circle-more"
        class="size-12 text-dimmed"
      />
      <div>
        <p class="font-medium text-highlighted">
          Preparando conversas
        </p>
        <p class="mt-1 text-sm text-muted">
          O atendimento aparecerá assim que os dados estiverem prontos.
        </p>
      </div>
    </div>

    <button
      v-for="{ conversation, imageEvidence } in conversationRows"
      :id="`communication-conversation-${conversation.id}`"
      :key="conversation.id"
      :ref="element => setConversationButton(conversation.id, element)"
      :data-conversation-id="conversation.id"
      type="button"
      class="flex w-full gap-3 border-l-2 p-3 text-left text-sm transition-colors sm:px-4 sm:py-4"
      :class="selectedId === conversation.id
        ? 'border-primary bg-primary/10'
        : openingId === conversation.id
          ? 'border-muted bg-elevated/60'
          : 'border-transparent hover:border-primary hover:bg-primary/5'"
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
        class="mt-0.5 shrink-0"
      />
      <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-center justify-between gap-3">
          <span class="truncate font-semibold text-highlighted">
            {{ communicationDisplayName(conversation) }}
          </span>
          <span class="shrink-0 text-[11px] text-muted">
            {{ formatCommunicationDate(conversation.last_message_at) }}
          </span>
        </div>

        <p v-if="communicationContactLabel(conversation)" class="mt-0.5 truncate text-xs text-muted">
          {{ communicationContactLabel(conversation) }}
        </p>

        <div
          v-if="imageEvidence"
          data-testid="communication-conversation-image-evidence"
          class="mt-2 flex min-w-0 items-center gap-2 rounded-md border border-default bg-elevated/60 p-1.5"
        >
          <div
            class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-md bg-accented"
          >
            <img
              v-if="imagePreviewAvailable(imageEvidence)"
              :src="imageEvidence.previewUrl || undefined"
              :alt="`Prévia da imagem recebida de ${communicationDisplayName(conversation)}`"
              class="size-full object-cover"
              loading="lazy"
              decoding="async"
              @error="markImagePreviewUnavailable(imageEvidence.messageId)"
            >
            <UIcon
              v-else
              name="i-lucide-image-off"
              class="size-5 text-dimmed"
              aria-hidden="true"
            />
          </div>
          <div class="min-w-0 flex-1">
            <p class="flex items-center gap-1 text-xs font-medium text-highlighted">
              <UIcon
                name="i-lucide-image"
                class="size-3.5 shrink-0 text-muted"
                aria-hidden="true"
              />
              <span class="truncate">Imagem recebida</span>
            </p>
            <p class="mt-0.5 truncate text-[11px] text-muted">
              {{ imagePreviewAvailable(imageEvidence) ? 'Prévia da última mensagem' : 'Prévia indisponível' }}
            </p>
          </div>
        </div>

        <div class="mt-1 flex min-w-0 items-center justify-between gap-2">
          <span class="truncate text-[11px] text-dimmed">
            {{ inboxName(conversation.inbox_id) }}
          </span>
          <UBadge
            v-if="openingId === conversation.id"
            label="Abrindo"
            color="neutral"
            variant="subtle"
            size="sm"
          />
          <UBadge
            v-else
            :label="COMMUNICATION_CONVERSATION_STATUS[conversation.status].label"
            :color="COMMUNICATION_CONVERSATION_STATUS[conversation.status].color"
            variant="subtle"
            size="sm"
          />
        </div>

        <div class="mt-2 flex min-w-0 flex-wrap items-center gap-1.5">
          <UBadge
            v-if="conversation.assignee_membership_id == null"
            label="Sem responsável"
            color="warning"
            variant="soft"
            size="sm"
          />
          <UBadge
            v-if="conversation.priority > 0"
            :label="`Prioridade ${conversation.priority}`"
            color="error"
            variant="soft"
            size="sm"
          />
          <UBadge
            v-for="label in conversation.labels?.slice(0, 2)"
            :key="label.id"
            :label="label.name"
            color="neutral"
            variant="outline"
            size="sm"
            class="max-w-28 truncate"
          />
        </div>
      </div>
    </button>

    <div
      v-if="conversations.length && (hasMore || loadingMore || loadMoreError)"
      class="space-y-2 border-t border-default p-3 text-center"
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
        :loading="loadingMore"
        :disabled="loadingMore"
        :aria-label="loadingMore ? 'Carregando mais conversas' : 'Carregar mais conversas'"
        data-testid="communication-load-more"
        @click="emit('loadMore')"
      />
      <p
        v-if="total !== undefined"
        class="text-xs text-muted"
      >
        {{ conversations.length }} de {{ total }} conversas carregadas
      </p>
    </div>

    <div
      v-if="empty && !conversations.length"
      class="flex h-full min-h-64 flex-col items-center justify-center gap-3 p-8 text-center"
    >
      <UIcon
        name="i-lucide-message-circle-dashed"
        class="size-12 text-dimmed"
      />
      <div>
        <p class="font-medium text-highlighted">
          Nenhuma conversa encontrada
        </p>
        <p class="mt-1 text-sm text-muted">
          Ajuste os filtros ou aguarde uma nova mensagem.
        </p>
      </div>
    </div>
  </div>
</template>
