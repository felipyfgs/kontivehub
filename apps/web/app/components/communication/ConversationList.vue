<script setup lang="ts">
import CommunicationConversationActions from './ConversationActions.vue'
import type {
  CommunicationConversation,
  CommunicationConversationActionPayload,
  CommunicationInbox,
  CommunicationLabel
} from '~/types/communication'
import type { WorkDepartment } from '~/types/work'
import {
  COMMUNICATION_CONVERSATION_STATUS,
  communicationConversationImageEvidence,
  communicationDisplayName,
  communicationListPhoneLine,
  communicationPreviewText,
  communicationProfilePictureSrc,
  formatCommunicationDate
} from '~/utils/communication'

const apiBase = String(useRuntimeConfig().public.apiBase || '')

const props = withDefaults(defineProps<{
  conversations: CommunicationConversation[]
  inboxes: CommunicationInbox[]
  departments?: WorkDepartment[]
  labels?: CommunicationLabel[]
  selectedId?: number | null
  openingId?: number | null
  selectedIds?: ReadonlySet<number> | Set<number>
  loading?: boolean
  empty?: boolean
  hasMore?: boolean
  loadingMore?: boolean
  loadMoreError?: string | null
  total?: number
  canView?: boolean
  canReply?: boolean
  actionDisabled?: boolean
}>(), {
  departments: () => [],
  labels: () => []
})

/** Altura base de 92 px em 16 px, ampliada junto com a preferência de fonte. */
const ROW_HEIGHT_REM = 5.75
const DEFAULT_ROOT_FONT_SIZE_PX = 16
const OVERSCAN = 6
const skeletonRows = Array.from({ length: 8 }, (_, index) => index)

const emit = defineEmits<{
  'select': [conversation: CommunicationConversation]
  'prefetch': [conversationId: number]
  'loadMore': []
  'toggle-select': [conversationId: number, selected: boolean]
  'action': [payload: CommunicationConversationActionPayload]
}>()

const listRoot = ref<HTMLElement | null>(null)
const rowProbe = ref<HTMLElement | null>(null)
const scrollTop = ref(0)
const viewportHeight = ref(600)
const rowHeightPx = ref(ROW_HEIGHT_REM * DEFAULT_ROOT_FONT_SIZE_PX)
const rowHeightStyle = computed(() => ({ height: `${rowHeightPx.value}px` }))
const conversationButtons = new Map<number, HTMLButtonElement>()
const showInboxName = computed(() => props.inboxes.length > 1)

const selectedSet = computed(() => props.selectedIds ?? new Set<number>())

const virtualRange = computed(() => {
  const count = props.conversations.length
  if (count === 0) {
    return { start: 0, end: 0, offsetY: 0, totalHeight: 0 }
  }
  const visible = Math.ceil(viewportHeight.value / rowHeightPx.value) + OVERSCAN * 2
  const rawStart = Math.max(0, Math.floor(scrollTop.value / rowHeightPx.value) - OVERSCAN)
  const start = Math.min(rawStart, Math.max(0, count - visible))
  const end = Math.min(count, start + visible)
  return {
    start,
    end,
    offsetY: start * rowHeightPx.value,
    totalHeight: count * rowHeightPx.value
  }
})

const virtualRows = computed(() =>
  props.conversations.slice(virtualRange.value.start, virtualRange.value.end)
)

function onListScroll(event: Event): void {
  const target = event.target
  if (!(target instanceof HTMLElement)) return
  scrollTop.value = target.scrollTop
}

function measureViewport(): void {
  if (!listRoot.value) return
  const measuredRowHeight = rowProbe.value?.getBoundingClientRect().height
  const rootFontSize = Number.parseFloat(getComputedStyle(document.documentElement).fontSize)
  const effectiveRootFontSize = Number.isFinite(rootFontSize) && rootFontSize > 0
    ? rootFontSize
    : DEFAULT_ROOT_FONT_SIZE_PX
  rowHeightPx.value = measuredRowHeight && measuredRowHeight > 0
    ? measuredRowHeight
    : ROW_HEIGHT_REM * effectiveRootFontSize
  viewportHeight.value = listRoot.value.clientHeight || 600
}

let resizeObserver: ResizeObserver | null = null

onMounted(() => {
  measureViewport()
  if (!listRoot.value || typeof ResizeObserver === 'undefined') return
  resizeObserver = new ResizeObserver(() => measureViewport())
  resizeObserver.observe(listRoot.value)
  if (rowProbe.value) resizeObserver.observe(rowProbe.value)
})

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
  resizeObserver = null
})

function setConversationButton(conversationId: number, element: unknown): void {
  if (element instanceof HTMLButtonElement) {
    conversationButtons.set(conversationId, element)
    return
  }
  conversationButtons.delete(conversationId)
}

async function focusConversation(conversationId: number): Promise<boolean> {
  // Garante que a linha virtualizada entre na janela antes de focar.
  const index = props.conversations.findIndex(item => item.id === conversationId)
  if (index >= 0 && listRoot.value) {
    const top = index * rowHeightPx.value
    const bottom = top + rowHeightPx.value
    const viewTop = listRoot.value.scrollTop
    const viewBottom = viewTop + listRoot.value.clientHeight
    if (top < viewTop || bottom > viewBottom) {
      listRoot.value.scrollTo({ top: Math.max(0, top - rowHeightPx.value), behavior: 'auto' })
      scrollTop.value = listRoot.value.scrollTop
    }
  }
  await nextTick()
  const button = conversationButtons.get(conversationId)
  if (!button?.isConnected) return false
  button.focus({ preventScroll: true })
  button.scrollIntoView({ block: 'nearest' })
  return document.activeElement === button
}

async function focusList(): Promise<boolean> {
  await nextTick()
  if (!listRoot.value?.isConnected) return false
  listRoot.value.focus({ preventScroll: true })
  return document.activeElement === listRoot.value
}

defineExpose({ focusConversation, focusList })

function inboxName(id: number): string {
  return props.inboxes.find(inbox => inbox.id === id)?.name || `Inbox #${id}`
}

function inboxFor(id: number): CommunicationInbox | null {
  return props.inboxes.find(inbox => inbox.id === id) ?? null
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

function isSelected(conversationId: number): boolean {
  return selectedSet.value.has(conversationId)
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

function onCheckboxChange(conversationId: number, value: boolean | 'indeterminate'): void {
  emit('toggle-select', conversationId, value === true)
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div
      ref="listRoot"
      data-testid="communication-conversation-list"
      tabindex="-1"
      role="region"
      aria-label="Lista de conversas"
      class="min-h-0 flex-1 overflow-y-auto focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary/50"
      @scroll.passive="onListScroll"
    >
      <div
        ref="rowProbe"
        class="pointer-events-none absolute invisible w-px"
        :style="{ height: `${ROW_HEIGHT_REM}rem` }"
        aria-hidden="true"
      />
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
          :style="rowHeightStyle"
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

      <div
        v-else-if="conversations.length"
        class="relative w-full"
        :style="{ height: `${virtualRange.totalHeight}px` }"
        data-testid="communication-conversation-virtual"
      >
        <div
          class="absolute inset-x-0 top-0 divide-y divide-default"
          role="list"
          :style="{ transform: `translateY(${virtualRange.offsetY}px)` }"
        >
          <div
            v-for="(conversation, offset) in virtualRows"
            :key="conversation.id"
            role="listitem"
            :aria-posinset="virtualRange.start + offset + 1"
            :aria-setsize="total ?? conversations.length"
            class="group/row flex w-full shrink-0 items-center gap-1.5 overflow-hidden border-l-2 px-2 py-2.5 transition-colors"
            :style="rowHeightStyle"
            :class="[
              selectedId === conversation.id
                ? 'border-primary bg-primary/10'
                : openingId === conversation.id
                  ? 'border-muted bg-elevated/60'
                  : isSelected(conversation.id)
                    ? 'border-primary/40 bg-primary/5'
                    : 'border-transparent hover:border-primary hover:bg-primary/5'
            ]"
            :data-testid="`communication-conversation-row-${conversation.id}`"
          >
            <div
              class="group/avatar relative size-8 shrink-0"
              :data-testid="`communication-conversation-avatar-select-${conversation.id}`"
            >
              <UAvatar
                :src="communicationProfilePictureSrc(conversation.contact, apiBase)"
                :alt="communicationDisplayName(conversation)"
                size="md"
                class="size-8"
                :data-testid="`communication-conversation-avatar-${conversation.id}`"
              />
              <div
                class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-full bg-default/45 opacity-0 backdrop-blur-[2px] transition-[opacity,background-color] group-hover/avatar:pointer-events-auto group-hover/avatar:opacity-100 group-focus-within/row:pointer-events-auto group-focus-within/row:opacity-100 [@media(pointer:coarse)]:pointer-events-auto [@media(pointer:coarse)]:opacity-100"
                :class="isSelected(conversation.id)
                  ? 'pointer-events-auto bg-default/75 opacity-100'
                  : ''"
                @click.stop="onCheckboxChange(conversation.id, !isSelected(conversation.id))"
              >
                <UCheckbox
                  :model-value="isSelected(conversation.id)"
                  size="md"
                  :aria-label="`Selecionar ${communicationDisplayName(conversation)}`"
                  :data-testid="`communication-conversation-check-${conversation.id}`"
                  @update:model-value="value => onCheckboxChange(conversation.id, value)"
                  @click.stop
                  @keydown.enter.stop.prevent="onCheckboxChange(conversation.id, !isSelected(conversation.id))"
                  @keydown.space.stop
                />
              </div>
            </div>

            <button
              :id="`communication-conversation-${conversation.id}`"
              :ref="element => setConversationButton(conversation.id, element)"
              :data-conversation-id="conversation.id"
              type="button"
              class="flex min-w-0 flex-1 items-center gap-3 text-left"
              :aria-current="selectedId === conversation.id ? 'true' : undefined"
              :aria-busy="openingId === conversation.id"
              @pointerenter="emit('prefetch', conversation.id)"
              @pointerdown="emit('prefetch', conversation.id)"
              @focus="emit('prefetch', conversation.id)"
              @click="emit('select', conversation)"
            >
              <div class="flex min-h-0 min-w-0 flex-1 flex-col justify-center gap-1">
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

                <p
                  class="truncate text-[11px] leading-4 text-muted"
                  data-testid="communication-conversation-secondary"
                >
                  {{ phoneLine(conversation) }}
                </p>

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

            <CommunicationConversationActions
              :conversation="conversation"
              :inbox="inboxFor(conversation.inbox_id)"
              :departments="departments"
              :labels="labels"
              :can-view="Boolean(canView)"
              :can-reply="Boolean(canReply)"
              :disabled="actionDisabled"
              :test-id="`communication-conversation-menu-${conversation.id}`"
              @action="emit('action', $event)"
            />
          </div>
        </div>
      </div>

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
        <ShellInfiniteTableLoader
          :loading="Boolean(loadingMore)"
          :has-more="Boolean(hasMore && !loadMoreError)"
          data-testid="communication-load-more-sentinel"
          @load="emit('loadMore')"
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
  </div>
</template>
