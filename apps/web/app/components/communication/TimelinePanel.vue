<script setup lang="ts">
import { usePreferredReducedMotion } from '@vueuse/core'
import CommunicationConversationActions from './ConversationActions.vue'
import type {
  CommunicationCannedResponse,
  CommunicationComposerPayload,
  CommunicationConversation,
  CommunicationConversationActionPayload,
  CommunicationConversationSignals,
  CommunicationConversationTimelineState,
  CommunicationInbox,
  CommunicationLabel,
  CommunicationMessage
} from '~/types/communication'
import type { WorkDepartment } from '~/types/work'
import {
  COMMUNICATION_CONVERSATION_STATUS,
  communicationMessageStatusMeta,
  communicationContactLabel,
  communicationDisplayName,
  communicationMessageSummary,
  communicationProfilePictureSrc,
  formatCommunicationDate
} from '~/utils/communication'
import { COMMUNICATION_REACTION_EMOJIS } from '~/utils/communication-composer'
import {
  appendedCommunicationMessages,
  communicationNewMessagesLabel,
  communicationUserScrollBehavior,
  isCommunicationTimelineNearBottom,
  shouldFollowCommunicationTimeline
} from '~/utils/communication-timeline'

const apiBase = String(useRuntimeConfig().public.apiBase || '')
const toast = useToast()

const props = defineProps<{
  conversation: CommunicationConversation
  inbox?: CommunicationInbox | null
  signals?: CommunicationConversationSignals
  cannedResponses: CommunicationCannedResponse[]
  departments: WorkDepartment[]
  labels: CommunicationLabel[]
  canView: boolean
  canReply: boolean
  operational: boolean
  outboundOperational: boolean
  unavailableReason?: string
  sending?: boolean
  actionLoadingId?: number | null
  mobile?: boolean
  contextOpen?: boolean
  timeline?: CommunicationConversationTimelineState | null
  viewportActive?: boolean
  highlightedMessageId?: number | null
  actionDisabled?: boolean
}>()

const emit = defineEmits<{
  close: []
  toggleContext: []
  action: [payload: CommunicationConversationActionPayload]
  send: [
    payload: CommunicationComposerPayload,
    acknowledge: (ok: boolean) => void
  ]
  download: [message: CommunicationMessage, attachmentId: number, filename: string]
  edit: [message: CommunicationMessage, text: string, acknowledge: (ok: boolean) => void]
  revoke: [message: CommunicationMessage]
  react: [message: CommunicationMessage, emoji: string | null]
  vote: [message: CommunicationMessage, optionNames: string[]]
  receipt: [message: CommunicationMessage, receipt: 'READ' | 'PLAYED']
  recover: [message: CommunicationMessage, operation: 'UNAVAILABLE' | 'MEDIA_RETRY']
  presence: [presence: 'COMPOSING' | 'PAUSED' | 'RECORDING']
  loadOlder: [acknowledge: (ok: boolean) => void]
  loadNewer: [acknowledge: (ok: boolean) => void]
  timelineState: [state: {
    conversationId: number
    rendered: boolean
    visible: boolean
    atEnd: boolean
  }]
}>()

const messagesContainer = ref<HTMLElement | null>(null)
const messagesContent = ref<HTMLElement | null>(null)
const replyTo = ref<CommunicationMessage | null>(null)
const editTarget = ref<CommunicationMessage | null>(null)
const editDraft = ref('')
const revokeTarget = ref<CommunicationMessage | null>(null)
const activeHighlightedMessageId = ref<number | null>(null)
const pendingNewMessages = ref(0)
const followingLatest = ref(true)
const paginationDirection = ref<'older' | 'newer' | null>(null)
const preferredReducedMotion = usePreferredReducedMotion()
let highlightTimer: ReturnType<typeof setTimeout> | null = null
let messagesResizeObserver: ResizeObserver | null = null
let renderedConversationId: number | null = null
let renderedMessageIds = new Set<number>()
let messagesWatchEpoch = 0
let paginationScrollHeight = 0
let paginationScrollTop = 0
let paginationRequestEpoch = 0
let paginationResetTimer: ReturnType<typeof setTimeout> | null = null

const chatPresenceLabel = computed(() => {
  const signal = props.signals?.chat
  if (!signal) return null
  return signal.presence === 'RECORDING' || signal.media === 'AUDIO'
    ? 'gravando áudio…'
    : 'digitando…'
})

const newMessagesLabel = computed(() => communicationNewMessagesLabel(pendingNewMessages.value))
const editOpen = computed({
  get: () => editTarget.value !== null,
  set: (open: boolean) => {
    if (!open) closeEdit()
  }
})
const revokeOpen = computed({
  get: () => revokeTarget.value !== null,
  set: (open: boolean) => {
    if (!open) closeRevoke()
  }
})

function quotedMessage(message: CommunicationMessage): CommunicationMessage | undefined {
  return props.conversation.messages?.find(item => item.id === message.reply_to_message_id)
}

function openEdit(message: CommunicationMessage): void {
  editTarget.value = message
  editDraft.value = message.body || ''
}

function submitEdit(): void {
  const target = editTarget.value
  const text = editDraft.value.trim()
  if (!target || !text || text === target.body?.trim()) return
  emit('edit', target, text, (ok) => {
    if (ok) editTarget.value = null
  })
}

interface MessageActionItem {
  label: string
  icon: string
  disabled?: boolean
  color?: 'error'
  onSelect: () => void
}

function closeEdit(): void {
  editTarget.value = null
}

function closeRevoke(): void {
  revokeTarget.value = null
}

function confirmRevoke(): void {
  if (!revokeTarget.value) return
  emit('revoke', revokeTarget.value)
  closeRevoke()
}

function messageActionItems(message: CommunicationMessage): MessageActionItem[][] {
  const remote = message.direction !== 'INTERNAL' && !message.metadata?.revoked
  const groups: MessageActionItem[][] = [[{
    label: 'Citar mensagem',
    icon: 'i-lucide-reply',
    disabled: !remote,
    onSelect: () => { replyTo.value = message }
  }]]
  if (remote) {
    groups.push([{
      label: 'Remover minha reação',
      icon: 'i-lucide-eraser',
      onSelect: () => emit('react', message, null)
    }])
  }
  if (message.direction === 'OUTBOUND' && message.body && !message.metadata?.revoked) {
    groups.push([
      {
        label: 'Editar mensagem',
        icon: 'i-lucide-pencil',
        onSelect: () => openEdit(message)
      },
      {
        label: 'Apagar para todos',
        icon: 'i-lucide-trash-2',
        color: 'error' as const,
        onSelect: () => { revokeTarget.value = message }
      }
    ])
  }
  if (message.direction === 'INBOUND') {
    groups.push([{
      label: 'Marcar como lida',
      icon: 'i-lucide-check-check',
      onSelect: () => emit('receipt', message, 'READ')
    }])
  }
  return groups
}

function isRemoteMessage(message: CommunicationMessage): boolean {
  return message.direction !== 'INTERNAL' && !message.metadata?.revoked
}

function scrollToMessage(messageId: number): boolean {
  const target = messagesContainer.value?.querySelector<HTMLElement>(`[data-message-id="${messageId}"]`)
  if (!target) return false
  target.scrollIntoView({
    behavior: communicationUserScrollBehavior(preferredReducedMotion.value === 'reduce'),
    block: 'center'
  })
  activeHighlightedMessageId.value = messageId
  if (highlightTimer) clearTimeout(highlightTimer)
  highlightTimer = setTimeout(() => {
    activeHighlightedMessageId.value = null
    highlightTimer = null
  }, 1_800)

  return true
}

function isNearLatest(): boolean {
  const container = messagesContainer.value
  if (!container) return true
  return isCommunicationTimelineNearBottom(container)
}

function documentIsVisible(): boolean {
  return !import.meta.client || document.visibilityState === 'visible'
}

function reportTimelineState(rendered = true): void {
  if (props.viewportActive === false) return
  emit('timelineState', {
    conversationId: props.conversation.id,
    rendered,
    visible: documentIsVisible(),
    atEnd: isNearLatest()
  })
}

function applyScrollToLatest(behavior: ScrollBehavior): void {
  const container = messagesContainer.value
  if (!container) return
  if (behavior === 'smooth') {
    container.scrollTo({ top: container.scrollHeight, behavior })
  } else {
    container.scrollTop = container.scrollHeight
  }
  followingLatest.value = true
  pendingNewMessages.value = 0
  nextTick(() => reportTimelineState())
}

function scrollToLatest(behavior: ScrollBehavior = 'auto'): void {
  followingLatest.value = true
  pendingNewMessages.value = 0
  nextTick(() => {
    applyScrollToLatest(behavior)
  })
}

function openLatestMessages(): void {
  scrollToLatest(communicationUserScrollBehavior(preferredReducedMotion.value === 'reduce'))
}

function handleMessagesScroll(): void {
  followingLatest.value = isNearLatest()
  if (followingLatest.value) pendingNewMessages.value = 0
  reportTimelineState()
}

function scrollToUnreadDivider(): boolean {
  const messageId = props.timeline?.divider_message_id
    ?? props.conversation.first_unread_message_id
  if (!messageId || !messagesContainer.value) return false
  const target = messagesContainer.value.querySelector<HTMLElement>(
    `[data-message-id="${messageId}"]`
  )
  if (!target) return false
  const containerRect = messagesContainer.value.getBoundingClientRect()
  const targetRect = target.getBoundingClientRect()
  messagesContainer.value.scrollTop = Math.max(
    0,
    messagesContainer.value.scrollTop + targetRect.top - containerRect.top - 56
  )
  followingLatest.value = isNearLatest()
  return true
}

function resetPagination(requestEpoch?: number): void {
  if (requestEpoch !== undefined && requestEpoch !== paginationRequestEpoch) return
  if (paginationResetTimer) clearTimeout(paginationResetTimer)
  paginationResetTimer = null
  paginationDirection.value = null
}

function cancelPagination(): void {
  paginationRequestEpoch++
  resetPagination()
}

function requestTimelinePage(direction: 'older' | 'newer', targetMessageId?: number): void {
  if (paginationDirection.value !== null || !messagesContainer.value) return
  const requestEpoch = ++paginationRequestEpoch
  const conversationId = props.conversation.id
  paginationDirection.value = direction
  paginationScrollHeight = messagesContainer.value.scrollHeight
  paginationScrollTop = messagesContainer.value.scrollTop
  paginationResetTimer = setTimeout(() => resetPagination(requestEpoch), 15_000)
  const acknowledge = async (ok: boolean) => {
    if (requestEpoch !== paginationRequestEpoch) return
    await nextTick()
    if (requestEpoch !== paginationRequestEpoch) return
    const container = messagesContainer.value
    if (ok && container && props.conversation.id === conversationId && direction === 'older') {
      container.scrollTop = paginationScrollTop
        + Math.max(0, container.scrollHeight - paginationScrollHeight)
    }
    if (targetMessageId && (!ok || !scrollToMessage(targetMessageId))) {
      toast.add({
        title: 'Mensagem indisponível',
        description: 'A mensagem citada não está mais disponível nesta conversa.',
        color: 'warning'
      })
    }
    resetPagination(requestEpoch)
    followingLatest.value = isNearLatest()
    reportTimelineState()
  }
  if (direction === 'older') {
    emit('loadOlder', acknowledge)
  } else {
    emit('loadNewer', acknowledge)
  }
}

function focusQuotedMessage(messageId: number): void {
  if (scrollToMessage(messageId)) return
  if (props.timeline?.meta.older_cursor) {
    requestTimelinePage('older', messageId)
    return
  }
  toast.add({
    title: 'Mensagem indisponível',
    description: 'A mensagem citada não está mais disponível nesta conversa.',
    color: 'warning'
  })
}

function handleVisibilityChange(): void {
  reportTimelineState()
}

watch(
  () => props.highlightedMessageId,
  async (messageId) => {
    if (!messageId) return
    await nextTick()
    scrollToMessage(messageId)
  },
  { flush: 'post' }
)

watch(
  () => ({
    conversationId: props.conversation.id,
    messages: [...(props.conversation.messages ?? [])]
  }),
  async ({ conversationId, messages }) => {
    const epoch = ++messagesWatchEpoch
    const conversationChanged = renderedConversationId !== conversationId
    if (conversationChanged) cancelPagination()
    const appended = conversationChanged
      ? messages
      : appendedCommunicationMessages(renderedMessageIds, messages)
    const wasNearBottom = conversationChanged || followingLatest.value || isNearLatest()

    renderedConversationId = conversationId
    renderedMessageIds = new Set(messages.map(message => message.id))
    if (conversationChanged) {
      followingLatest.value = true
      pendingNewMessages.value = 0
    }

    await nextTick()
    if (epoch !== messagesWatchEpoch) return
    const highlighted = props.highlightedMessageId
    if (highlighted && scrollToMessage(highlighted)) {
      pendingNewMessages.value = 0
    } else if (conversationChanged && scrollToUnreadDivider()) {
      pendingNewMessages.value = 0
    } else if (
      paginationDirection.value === null
      && shouldFollowCommunicationTimeline({ conversationChanged, wasNearBottom, appended })
    ) {
      applyScrollToLatest('auto')
    } else if (paginationDirection.value === null && appended.length) {
      pendingNewMessages.value += appended.length
    }
    reportTimelineState()
  },
  { immediate: true }
)

onMounted(() => {
  if (import.meta.client) {
    document.addEventListener('visibilitychange', handleVisibilityChange)
  }
  if (typeof ResizeObserver === 'undefined' || !messagesContent.value) return
  messagesResizeObserver = new ResizeObserver(() => {
    if (followingLatest.value && paginationDirection.value === null) applyScrollToLatest('auto')
  })
  messagesResizeObserver.observe(messagesContent.value)
})

onBeforeUnmount(() => {
  if (highlightTimer) clearTimeout(highlightTimer)
  cancelPagination()
  messagesResizeObserver?.disconnect()
  if (import.meta.client) {
    document.removeEventListener('visibilitychange', handleVisibilityChange)
  }
})

watch(
  () => props.viewportActive,
  (active) => {
    if (active !== false) nextTick(() => reportTimelineState())
  }
)
</script>

<template>
  <UDashboardPanel
    :id="`communication-timeline-${conversation.id}`"
    data-testid="communication-timeline-panel"
    class="min-w-0"
  >
    <UDashboardNavbar
      :title="communicationDisplayName(conversation)"
      :toggle="false"
    >
      <template #leading>
        <div class="flex items-center gap-1.5">
          <UButton
            v-if="mobile"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            aria-label="Voltar à lista"
            @click="emit('close')"
          />
          <UAvatar
            :src="communicationProfilePictureSrc(conversation.contact, apiBase)"
            :alt="communicationDisplayName(conversation)"
            size="sm"
            data-testid="communication-timeline-avatar"
          />
        </div>
      </template>

      <template #trailing>
        <UBadge
          :label="COMMUNICATION_CONVERSATION_STATUS[conversation.status].label"
          :color="COMMUNICATION_CONVERSATION_STATUS[conversation.status].color"
          variant="subtle"
        />
      </template>

      <template #right>
        <UTooltip :text="contextOpen ? 'Fechar contexto do contato' : 'Abrir contexto do contato'">
          <UButton
            :icon="contextOpen ? 'i-lucide-panel-right-close' : 'i-lucide-panel-right-open'"
            :color="contextOpen ? 'primary' : 'neutral'"
            :variant="contextOpen ? 'soft' : 'ghost'"
            :aria-label="contextOpen ? 'Fechar contexto do contato' : 'Abrir contexto do contato'"
            :aria-pressed="contextOpen"
            data-testid="communication-context-toggle"
            @click="emit('toggleContext')"
          />
        </UTooltip>
        <CommunicationConversationActions
          :conversation="conversation"
          :inbox="inbox"
          :departments="departments"
          :labels="labels"
          :can-view="canView"
          :can-reply="canReply"
          :disabled="actionDisabled"
          test-id="communication-timeline-actions"
          @action="emit('action', $event)"
        />
      </template>
    </UDashboardNavbar>

    <div class="flex min-w-0 items-center justify-between gap-3 border-b border-default px-4 py-2 text-xs text-muted sm:px-6">
      <div class="flex min-w-0 items-center gap-1.5">
        <span v-if="communicationContactLabel(conversation)" class="truncate">
          {{ communicationContactLabel(conversation) }}
        </span>
        <span v-if="communicationContactLabel(conversation)" aria-hidden="true">·</span>
        <span class="shrink-0">{{ inbox?.name || `Inbox #${conversation.inbox_id}` }}</span>
      </div>
      <span v-if="chatPresenceLabel" class="flex items-center gap-1.5 font-medium text-primary" data-testid="communication-chat-presence">
        <span class="size-1.5 animate-pulse rounded-full bg-primary" />
        {{ chatPresenceLabel }}
      </span>
      <span v-else-if="signals?.contact?.available" class="flex items-center gap-1.5 text-success" data-testid="communication-contact-online">
        <span class="size-1.5 rounded-full bg-success" />
        online
      </span>
      <span v-else-if="signals?.contact?.last_seen">
        visto por último em {{ formatCommunicationDate(signals.contact.last_seen) }}
      </span>
      <span v-else-if="conversation.snoozed_until">
        Adiada até {{ formatCommunicationDate(conversation.snoozed_until) }}
      </span>
      <span v-else-if="conversation.contact?.phone">
        {{ conversation.contact.phone }}
      </span>
      <span v-else>
        Número indisponível
      </span>
    </div>

    <div class="relative min-h-0 flex-1">
      <div
        ref="messagesContainer"
        class="h-full overflow-y-auto bg-elevated/20 p-4 sm:p-6"
        @scroll.passive="handleMessagesScroll"
      >
        <div ref="messagesContent" class="min-h-full">
          <UAlert
            v-if="timeline?.error"
            :title="timeline.error"
            description="As mensagens já carregadas permanecem disponíveis."
            color="warning"
            variant="subtle"
            class="mb-4"
          />

          <div
            v-if="timeline?.loading && !timeline.initialized"
            class="space-y-4"
            role="status"
            aria-live="polite"
            aria-label="Carregando timeline"
            data-testid="communication-timeline-skeleton"
          >
            <USkeleton class="ms-auto h-16 w-3/5 rounded-2xl" />
            <USkeleton class="h-20 w-2/3 rounded-2xl" />
            <USkeleton class="ms-auto h-14 w-1/2 rounded-2xl" />
          </div>

          <template v-else>
            <div
              v-if="timeline?.meta.older_cursor"
              class="mb-4 flex justify-center"
            >
              <UButton
                label="Carregar mensagens anteriores"
                icon="i-lucide-arrow-up"
                color="neutral"
                variant="subtle"
                size="sm"
                :loading="timeline.loading_older"
                :disabled="timeline.loading_newer"
                data-testid="communication-timeline-load-older"
                @click="requestTimelinePage('older')"
              />
            </div>

            <div
              v-if="!conversation.messages?.length"
              class="flex min-h-56 flex-col items-center justify-center gap-3 text-center"
            >
              <UIcon name="i-lucide-messages-square" class="size-12 text-dimmed" />
              <p class="text-sm text-muted">
                A timeline ainda não possui mensagens.
              </p>
            </div>

            <div v-else class="space-y-3.5 sm:space-y-4">
              <template
                v-for="message in conversation.messages"
                :key="message.id"
              >
                <div
                  v-if="(timeline?.divider_message_id ?? conversation.first_unread_message_id) === message.id"
                  data-testid="communication-unread-divider"
                  class="flex items-center gap-3 py-1"
                  role="separator"
                  aria-label="Mensagens não lidas"
                >
                  <div class="h-px flex-1 bg-primary/40" />
                  <span class="shrink-0 text-[11px] font-semibold uppercase tracking-wide text-primary">
                    Não lidas
                  </span>
                  <div class="h-px flex-1 bg-primary/40" />
                </div>
                <article
                  class="group/message flex scroll-m-8"
                  :class="message.direction === 'OUTBOUND' ? 'justify-end' : 'justify-start'"
                  :data-message-id="message.id"
                >
                  <div class="min-w-0 w-fit max-w-[92%] sm:max-w-[78%] lg:max-w-[72%]">
                    <div
                      data-testid="communication-message-bubble"
                      class="relative isolate inline-block w-fit max-w-full rounded-2xl px-3 py-2 shadow-xs ring-1 ring-inset transition sm:px-3.5 sm:py-2.5"
                      :class="[
                        message.direction === 'OUTBOUND'
                          ? 'rounded-br-md bg-primary/20 text-highlighted ring-primary/15'
                          : message.direction === 'INTERNAL'
                            ? 'rounded-bl-md bg-warning/10 text-highlighted ring-warning/40'
                            : 'rounded-bl-md bg-default text-highlighted ring-default',
                        activeHighlightedMessageId === message.id ? 'ring-2 ring-primary ring-offset-2 ring-offset-default' : ''
                      ]"
                    >
                      <span
                        aria-hidden="true"
                        class="pointer-events-none absolute bottom-px size-2.5 rotate-45 border-b border-l"
                        :class="message.direction === 'OUTBOUND'
                          ? '-right-1 border-primary/15 bg-primary/20'
                          : message.direction === 'INTERNAL'
                            ? '-left-1 border-warning/40 bg-warning/10'
                            : '-left-1 border-default bg-default'"
                      />

                      <div class="relative mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold leading-none opacity-80">
                        <UIcon
                          v-if="message.direction === 'INTERNAL'"
                          name="i-lucide-sticky-note"
                          class="size-3.5"
                        />
                        <UIcon
                          v-else-if="message.source === 'FISCAL_AUTOMATION'"
                          name="i-lucide-bot"
                          class="size-3.5"
                        />
                        <UIcon
                          v-else
                          :name="message.direction === 'OUTBOUND' ? 'i-lucide-send' : 'i-lucide-message-circle-reply'"
                          class="size-3.5"
                        />
                        <span>
                          {{ message.direction === 'INTERNAL'
                            ? 'Nota interna'
                            : message.source === 'FISCAL_AUTOMATION'
                              ? 'Automação fiscal'
                              : message.direction === 'OUTBOUND' ? 'Enviada · WhatsApp' : 'Recebida · WhatsApp' }}
                        </span>
                      </div>

                      <button
                        v-if="quotedMessage(message)"
                        type="button"
                        class="relative mb-2 block w-full rounded-lg border-l-2 border-primary bg-default/50 px-2.5 py-2 text-left text-xs text-muted transition-colors hover:bg-default/70 hover:text-default focus-visible:bg-default/70 focus-visible:text-default"
                        title="Ir para a mensagem citada"
                        @click="focusQuotedMessage(quotedMessage(message)!.id)"
                      >
                        <p class="line-clamp-2">
                          {{ communicationMessageSummary(quotedMessage(message)) }}
                        </p>
                      </button>

                      <CommunicationMessageContent
                        class="relative"
                        :message="message"
                        :can-reply="canReply && outboundOperational"
                        :action-loading="actionLoadingId === message.id"
                        @download="(target, attachmentId, filename) => emit('download', target, attachmentId, filename)"
                        @vote="(target, options) => emit('vote', target, options)"
                        @receipt="(target, receipt) => emit('receipt', target, receipt)"
                        @recover="(target, operation) => emit('recover', target, operation)"
                      />

                      <div v-if="message.metadata?.reactions?.length" class="relative mt-2 flex flex-wrap gap-1">
                        <UButton
                          v-for="(emoji, index) in message.metadata.reactions"
                          :key="`${emoji}-${index}`"
                          :label="emoji"
                          color="neutral"
                          variant="soft"
                          size="xs"
                          :disabled="!canReply || !outboundOperational"
                          aria-label="Reagir com o mesmo emoji"
                          @click="emit('react', message, emoji)"
                        />
                      </div>

                      <div
                        data-testid="communication-message-meta"
                        class="relative mt-1.5 flex min-h-6 items-end justify-end gap-1 text-[10px] leading-none opacity-75"
                        aria-label="Metadados da mensagem"
                      >
                        <div
                          v-if="canReply && outboundOperational"
                          class="mr-auto flex items-center gap-0.5 opacity-100 transition-opacity [@media(hover:hover)]:opacity-0 [@media(hover:hover)]:group-hover/message:opacity-100 group-focus-within/message:opacity-100"
                          data-testid="communication-message-actions"
                        >
                          <UPopover v-if="isRemoteMessage(message)">
                            <UButton
                              icon="i-lucide-smile-plus"
                              color="neutral"
                              variant="ghost"
                              size="xs"
                              :disabled="actionLoadingId === message.id"
                              aria-label="Reagir à mensagem"
                            />
                            <template #content>
                              <div class="grid w-64 grid-cols-8 gap-1 p-2" aria-label="Escolher reação">
                                <UButton
                                  v-for="emoji in COMMUNICATION_REACTION_EMOJIS"
                                  :key="emoji"
                                  :label="emoji"
                                  color="neutral"
                                  variant="ghost"
                                  size="sm"
                                  :aria-label="`Reagir com ${emoji}`"
                                  @click="emit('react', message, emoji)"
                                />
                              </div>
                            </template>
                          </UPopover>
                          <UDropdownMenu :items="messageActionItems(message)">
                            <UButton
                              icon="i-lucide-ellipsis"
                              color="neutral"
                              variant="ghost"
                              size="xs"
                              :loading="actionLoadingId === message.id"
                              aria-label="Ações da mensagem"
                            />
                          </UDropdownMenu>
                        </div>
                        <span v-if="message.metadata?.edited_at && !message.metadata.revoked">editada ·</span>
                        <time :datetime="message.occurred_at || undefined">
                          {{ formatCommunicationDate(message.occurred_at) }}
                        </time>
                        <template v-if="message.direction === 'OUTBOUND'">
                          <UIcon :name="communicationMessageStatusMeta(message.status).icon" class="size-3.5" />
                          <span class="sr-only">{{ communicationMessageStatusMeta(message.status).label }}</span>
                        </template>
                      </div>
                    </div>
                  </div>
                </article>
              </template>
            </div>

            <div
              v-if="timeline?.meta.newer_cursor"
              class="mt-4 flex justify-center"
            >
              <UButton
                label="Carregar mensagens posteriores"
                icon="i-lucide-arrow-down"
                color="neutral"
                variant="subtle"
                size="sm"
                :loading="timeline.loading_newer"
                :disabled="timeline.loading_older"
                data-testid="communication-timeline-load-newer"
                @click="requestTimelinePage('newer')"
              />
            </div>
          </template>
        </div>
      </div>

      <p
        v-if="pendingNewMessages > 0"
        class="sr-only"
        role="status"
        aria-live="polite"
      >
        {{ newMessagesLabel }} no fim da conversa.
      </p>
      <UButton
        v-if="pendingNewMessages > 0"
        :label="newMessagesLabel"
        icon="i-lucide-arrow-down"
        color="neutral"
        variant="subtle"
        size="sm"
        class="absolute bottom-4 left-1/2 z-10 -translate-x-1/2 shadow-lg"
        :aria-label="`${newMessagesLabel}. Ir para as mensagens mais recentes`"
        data-testid="communication-new-messages"
        @click="openLatestMessages"
      />
    </div>

    <CommunicationComposer
      :can-reply="canReply"
      :operational="operational"
      :outbound-operational="outboundOperational"
      :unavailable-reason="unavailableReason"
      :sending="sending"
      :conversation-id="conversation.id"
      :canned-responses="cannedResponses"
      :reply-to="replyTo"
      @send="(payload, acknowledge) => emit('send', payload, acknowledge)"
      @presence="presence => emit('presence', presence)"
      @cancel-reply="replyTo = null"
    />

    <ShellFormModal
      v-model:open="editOpen"
      title="Editar mensagem"
      description="A alteração será enviada ao WhatsApp e aplicada após a confirmação do gateway."
      submit-label="Enviar edição"
      submit-icon="i-lucide-pencil"
      :loading="actionLoadingId === editTarget?.id"
      :disabled="!editDraft.trim() || editDraft.trim() === editTarget?.body?.trim()"
      test-id="communication-edit-message"
      @cancel="closeEdit"
      @submit="submitEdit"
    >
      <template #body>
        <UFormField
          label="Novo texto"
          name="message"
          required
        >
          <UTextarea
            v-model="editDraft"
            :rows="5"
            autoresize
            class="w-full"
          />
        </UFormField>
      </template>
    </ShellFormModal>

    <ShellConfirmModal
      v-model:open="revokeOpen"
      title="Apagar mensagem para todos?"
      description="A revogação depende da janela e das regras do WhatsApp. O histórico auditável local será preservado."
      tone="danger"
      confirm-label="Apagar para todos"
      confirm-icon="i-lucide-trash-2"
      :loading="actionLoadingId === revokeTarget?.id"
      test-id="communication-revoke-message"
      @cancel="closeRevoke"
      @confirm="confirmRevoke"
    />
  </UDashboardPanel>
</template>
