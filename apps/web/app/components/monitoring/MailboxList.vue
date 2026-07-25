<script setup lang="ts">
/**
 * Lista de mensagens da Caixa Postal (arquétipo InboxList).
 * Casca sempre montada: empty/loading dentro do painel (não some a lista).
 */
import { mailboxTriageLabel } from '~/utils/mailbox-triage'

export interface MailboxListItem {
  id: number
  client_id?: number | null
  subject_preview?: string | null
  sender_label?: string | null
  triage_status?: string | null
  severity_hint?: string | null
  received_at_official?: string | null
  due_at?: string | null
  official_read_indicator?: string | boolean | null
  has_body?: boolean
  attachment_count?: number
}

const props = defineProps<{
  messages: MailboxListItem[]
  selectedId?: number | null
  loading?: boolean
}>()

const emit = defineEmits<{
  select: [id: number]
}>()

function isUnread(m: MailboxListItem) {
  const v = m.official_read_indicator
  if (v === false || v === 'UNREAD' || v === 'false') return true
  return m.triage_status === 'NEW'
}

/** Restaura foco no item da lista (após fechar slideover/detalhe). */
function focusMessage(id: number | null | undefined) {
  if (!id) return
  nextTick(() => {
    const el = document.querySelector<HTMLElement>(`[data-mailbox-id="${id}"]`)
    el?.focus()
  })
}

defineExpose({ focusMessage })
</script>

<template>
  <div
    class="min-h-0 flex-1 overflow-y-auto divide-y divide-default"
    data-testid="mailbox-list"
    role="listbox"
    aria-label="Mensagens da Caixa Postal"
    :aria-busy="loading || undefined"
  >
    <!-- Casca sempre presente: empty/loading no corpo, não substitui o painel -->
    <div
      v-if="loading && !props.messages.length"
      class="flex flex-col items-center justify-center gap-2 p-12 text-center"
      data-testid="fiscal-empty-loading"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="size-8 animate-spin text-dimmed"
      />
      <p class="text-sm text-muted">
        Carregando mensagens…
      </p>
    </div>
    <MonitoringTableEmptyState
      v-else-if="!props.messages.length"
      kind="empty"
      title="Nenhuma mensagem"
      description="Nenhuma mensagem retornada pela API."
      data-testid="fiscal-empty"
    />
    <button
      v-for="mail in props.messages"
      :id="`mailbox-item-${mail.id}`"
      :key="mail.id"
      type="button"
      :data-mailbox-id="mail.id"
      class="w-full cursor-pointer border-l-2 p-4 text-start text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary sm:px-6"
      :class="[
        isUnread(mail) ? 'font-medium text-highlighted' : 'text-toned',
        selectedId === mail.id
          ? 'border-primary bg-primary/10'
          : 'border-transparent hover:border-primary hover:bg-primary/5'
      ]"
      :aria-current="selectedId === mail.id ? 'true' : undefined"
      :aria-selected="selectedId === mail.id"
      role="option"
      @click="emit('select', mail.id)"
    >
      <div class="flex items-center justify-between gap-2">
        <div class="flex min-w-0 items-center gap-2">
          <span class="truncate">{{ mail.sender_label || `Cliente #${mail.client_id ?? '—'}` }}</span>
          <UChip
            v-if="isUnread(mail)"
            size="sm"
          />
        </div>
        <span class="shrink-0 text-xs text-muted">
          {{ formatDateTime(mail.received_at_official) }}
        </span>
      </div>
      <p class="mt-0.5 truncate">
        {{ mail.subject_preview || 'Sem assunto' }}
      </p>
      <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
        <UBadge
          color="neutral"
          variant="subtle"
          size="sm"
        >
          {{ mailboxTriageLabel(mail.triage_status) }}
        </UBadge>
        <span v-if="mail.due_at">Prazo: {{ formatDateTime(mail.due_at) }}</span>
        <span v-if="(mail.attachment_count || 0) > 0">
          {{ mail.attachment_count }} anexo(s)
        </span>
      </div>
    </button>
  </div>
</template>
