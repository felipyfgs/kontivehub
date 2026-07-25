<script setup lang="ts">
import type { CommunicationAttachment, CommunicationMessage } from '~/types/communication'
import {
  communicationAttachmentFilename,
  communicationPollVoteCount
} from '~/utils/communication'

const props = defineProps<{
  message: CommunicationMessage
  canReply: boolean
  actionLoading?: boolean
}>()

const emit = defineEmits<{
  download: [message: CommunicationMessage, attachmentId: number, filename: string]
  vote: [message: CommunicationMessage, optionNames: string[]]
  receipt: [message: CommunicationMessage, receipt: 'READ' | 'PLAYED']
  recover: [message: CommunicationMessage, operation: 'UNAVAILABLE' | 'MEDIA_RETRY']
}>()

const pollSelection = ref<string[]>([])
const playedReceiptSent = ref(false)
const pollOptions = computed(() => props.message.metadata?.poll?.options ?? [])
const stickerAttachment = computed(() => props.message.kind === 'STICKER'
  ? props.message.attachments?.find(attachment => !attachment.purged_at) ?? null
  : null)
const selectableOptions = computed(() => Math.max(
  1,
  Math.min(pollOptions.value.length || 1, props.message.metadata?.poll?.selectable_options || 1)
))

function attachmentIcon(attachment: CommunicationAttachment): string {
  if (props.message.kind === 'STICKER') return 'i-lucide-sticker'
  if (attachment.mime_type.startsWith('image/')) return 'i-lucide-image'
  if (attachment.mime_type.startsWith('audio/')) return 'i-lucide-audio-lines'
  if (attachment.mime_type.startsWith('video/')) return 'i-lucide-video'
  return 'i-lucide-file-text'
}

function attachmentSize(attachment: CommunicationAttachment): string {
  if (attachment.size_bytes < 1024) return `${attachment.size_bytes} B`
  const kilobytes = attachment.size_bytes / 1024
  if (kilobytes < 1024) return `${Math.ceil(kilobytes)} KB`
  return `${(kilobytes / 1024).toFixed(1)} MB`
}

function downloadAttachment(attachment: CommunicationAttachment): void {
  emit(
    'download',
    props.message,
    attachment.id,
    communicationAttachmentFilename(props.message, attachment.id)
  )
}

function markAudioPlayed(): void {
  if (playedReceiptSent.value || props.message.direction !== 'INBOUND' || !props.canReply) return
  playedReceiptSent.value = true
  emit('receipt', props.message, 'PLAYED')
}

function selectPollOption(option: string): void {
  if (!props.canReply || props.actionLoading) return
  if (selectableOptions.value === 1) {
    emit('vote', props.message, [option])
    return
  }
  pollSelection.value = pollSelection.value.includes(option)
    ? pollSelection.value.filter(item => item !== option)
    : pollSelection.value.length < selectableOptions.value
      ? [...pollSelection.value, option]
      : pollSelection.value
}

function submitPollVote(): void {
  if (!pollSelection.value.length) return
  emit('vote', props.message, [...pollSelection.value])
}
</script>

<template>
  <div data-testid="communication-message-content">
    <div
      v-if="message.metadata?.revoked"
      class="flex items-center gap-2 text-sm italic opacity-75"
    >
      <UIcon name="i-lucide-ban" class="size-4" />
      <span>Mensagem apagada</span>
    </div>

    <template v-else>
      <div
        v-if="message.kind === 'LOCATION' && message.metadata?.location"
        class="rounded-lg border border-current/20 bg-elevated/30 p-3"
      >
        <div class="flex items-start gap-2">
          <UIcon name="i-lucide-map-pin" class="mt-0.5 size-5 shrink-0" />
          <div class="min-w-0">
            <p class="font-medium">
              {{ message.metadata.location.name || 'Localização compartilhada' }}
            </p>
            <p v-if="message.metadata.location.address" class="mt-0.5 text-xs opacity-80">
              {{ message.metadata.location.address }}
            </p>
            <p class="mt-1 font-mono text-[11px] opacity-70">
              {{ message.metadata.location.latitude.toFixed(6) }},
              {{ message.metadata.location.longitude.toFixed(6) }}
            </p>
          </div>
        </div>
      </div>

      <div
        v-else-if="message.kind === 'CONTACT' && message.metadata?.contact"
        class="rounded-lg border border-current/20 bg-elevated/30 p-3"
      >
        <div class="flex items-center gap-3">
          <UAvatar
            :alt="message.metadata.contact.display_name || 'Contato compartilhado'"
            icon="i-lucide-user"
            size="md"
          />
          <div class="min-w-0">
            <p class="truncate font-medium">
              {{ message.metadata.contact.display_name || 'Contato compartilhado' }}
            </p>
            <p class="text-xs opacity-75">
              Cartão de contato do WhatsApp
            </p>
          </div>
        </div>
      </div>

      <div
        v-else-if="message.kind === 'POLL' && message.metadata?.poll"
        class="rounded-lg border border-current/20 bg-elevated/30 p-3"
      >
        <div class="mb-2 flex items-start gap-2">
          <UIcon name="i-lucide-list-checks" class="mt-0.5 size-4 shrink-0" />
          <div>
            <p class="font-medium">
              {{ message.metadata.poll.name || message.body || 'Enquete' }}
            </p>
            <p v-if="selectableOptions > 1" class="text-[11px] opacity-70">
              Selecione até {{ selectableOptions }} opções
            </p>
          </div>
        </div>
        <div class="space-y-1.5">
          <UButton
            v-for="option in pollOptions"
            :key="option"
            color="neutral"
            :variant="pollSelection.includes(option) ? 'soft' : 'outline'"
            size="sm"
            block
            class="justify-between text-left"
            :disabled="!canReply || actionLoading"
            @click="selectPollOption(option)"
          >
            <span class="truncate">{{ option }}</span>
            <span class="shrink-0 text-[11px] opacity-70">
              {{ communicationPollVoteCount(message, option) }} voto(s)
            </span>
          </UButton>
        </div>
        <UButton
          v-if="selectableOptions > 1"
          label="Enviar voto"
          icon="i-lucide-send"
          color="primary"
          size="sm"
          class="mt-2"
          :loading="actionLoading"
          :disabled="!pollSelection.length"
          @click="submitPollVote"
        />
      </div>

      <div
        v-else-if="message.kind === 'INTERACTIVE' && message.metadata?.interactive"
        class="rounded-lg border border-current/20 bg-elevated/30 p-3"
      >
        <div class="flex items-start gap-2">
          <UIcon name="i-lucide-mouse-pointer-click" class="mt-0.5 size-4 shrink-0" />
          <div class="min-w-0">
            <p class="font-medium">
              {{ message.metadata.interactive.title || 'Mensagem interativa' }}
            </p>
            <p v-if="message.metadata.interactive.description" class="mt-0.5 text-xs opacity-80">
              {{ message.metadata.interactive.description }}
            </p>
          </div>
        </div>
        <div v-if="message.metadata.interactive.options?.length" class="mt-2 flex flex-wrap gap-1.5">
          <UBadge
            v-for="option in message.metadata.interactive.options"
            :key="option"
            :label="option"
            color="neutral"
            variant="outline"
          />
        </div>
        <p v-if="message.metadata.interactive_response?.text" class="mt-2 text-xs opacity-80">
          Resposta: {{ message.metadata.interactive_response.text }}
        </p>
      </div>

      <div
        v-else-if="message.kind === 'STICKER'"
        class="py-1"
      >
        <img
          v-if="stickerAttachment?.preview_url"
          :src="stickerAttachment.preview_url"
          :alt="stickerAttachment.filename"
          class="max-h-48 max-w-48 object-contain"
          loading="lazy"
        >
        <div v-else class="flex items-center gap-2 text-sm">
          <UIcon name="i-lucide-sticker" class="size-8" />
          <span>Sticker</span>
        </div>
        <UButton
          v-if="stickerAttachment"
          :label="stickerAttachment.filename"
          icon="i-lucide-download"
          color="neutral"
          variant="ghost"
          size="xs"
          class="mt-1 max-w-48"
          :disabled="!!stickerAttachment.purged_at"
          @click="downloadAttachment(stickerAttachment)"
        />
      </div>

      <p
        v-if="message.body && message.kind !== 'POLL'"
        class="whitespace-pre-wrap break-words text-sm"
      >
        {{ message.body }}
      </p>

      <div
        v-if="message.kind !== 'STICKER' && message.attachments?.length"
        class="mt-2 space-y-2"
      >
        <div
          v-for="attachment in message.attachments"
          :key="attachment.id"
          class="overflow-hidden rounded-lg bg-elevated/40"
        >
          <img
            v-if="attachment.preview_url && attachment.mime_type.startsWith('image/') && !attachment.purged_at"
            :src="attachment.preview_url"
            :alt="attachment.filename"
            class="max-h-80 w-full bg-black/5 object-contain"
            loading="lazy"
          >
          <audio
            v-else-if="attachment.preview_url && attachment.mime_type.startsWith('audio/') && !attachment.purged_at"
            :src="attachment.preview_url"
            controls
            preload="metadata"
            class="block w-full max-w-full px-2 py-2"
            @play="markAudioPlayed"
          />
          <video
            v-else-if="attachment.preview_url && attachment.mime_type.startsWith('video/') && !attachment.purged_at"
            :src="attachment.preview_url"
            controls
            playsinline
            preload="metadata"
            class="max-h-80 w-full bg-black object-contain"
          />

          <button
            type="button"
            class="flex w-full items-center gap-2 px-2.5 py-2 text-left text-xs transition-colors hover:bg-elevated/70 disabled:opacity-60"
            :disabled="!!attachment.purged_at"
            @click="downloadAttachment(attachment)"
          >
            <UIcon :name="attachmentIcon(attachment)" class="size-4 shrink-0" />
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ attachment.filename }}</span>
              <span class="block truncate opacity-70">{{ attachment.mime_type }} · {{ attachmentSize(attachment) }}</span>
            </span>
            <UIcon name="i-lucide-download" class="size-3.5 shrink-0" />
          </button>
        </div>
      </div>

      <div v-if="message.metadata?.media_state || message.metadata?.view_once || message.metadata?.history" class="mt-2 flex flex-wrap gap-1">
        <UBadge
          v-if="message.metadata.view_once"
          label="Visualização única"
          color="warning"
          variant="subtle"
        />
        <UBadge
          v-if="message.metadata.history"
          label="Histórico importado"
          color="neutral"
          variant="subtle"
        />
        <UBadge
          v-if="message.metadata.media_state"
          :label="`Mídia: ${message.metadata.media_state}`"
          :color="message.metadata.media_state === 'FAILED' ? 'error' : 'neutral'"
          variant="subtle"
        />
      </div>

      <div
        v-if="message.direction === 'INBOUND' && canReply"
        class="mt-2 flex flex-wrap gap-1"
      >
        <UButton
          v-if="message.metadata?.media_state === 'FAILED'"
          label="Tentar recuperar mídia"
          icon="i-lucide-refresh-cw"
          color="warning"
          variant="ghost"
          size="xs"
          :loading="actionLoading"
          @click="emit('recover', message, 'MEDIA_RETRY')"
        />
        <UButton
          v-if="!message.body && !message.attachments?.length && !message.metadata?.revoked"
          label="Solicitar mensagem"
          icon="i-lucide-message-square-more"
          color="neutral"
          variant="ghost"
          size="xs"
          :loading="actionLoading"
          @click="emit('recover', message, 'UNAVAILABLE')"
        />
      </div>
    </template>
  </div>
</template>
