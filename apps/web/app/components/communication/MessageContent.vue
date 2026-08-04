<script setup lang="ts">
import type { Attachment, Message, MessageContact, MessageContent } from '~/types/communication/messages'
import {
  communicationAttachmentFilename,
  communicationAvailabilityPlaceholder,
  communicationMessageBody,
  communicationPollVoteCount
} from '~/utils/communication'
import { apiErrorMessage } from '~/utils/api-error'
import { resolveApiUrl } from '~/utils/api-url'

const apiBase = String(useRuntimeConfig().public.apiBase || '')

const props = defineProps<{
  message: Message
  canReply: boolean
  canManageContacts?: boolean
  actionLoading?: boolean
}>()

const emit = defineEmits<{
  download: [message: Message, attachmentId: number, filename: string]
  openMedia: [message: Message, attachmentId: number]
  vote: [message: Message, optionNames: string[]]
  receipt: [message: Message, receipt: 'READ' | 'PLAYED']
  recover: [message: Message, operation: 'MEDIA_RETRY']
}>()

const pollSelection = ref<string[]>([])
const playedReceiptSent = ref(false)
const contactPhoneOpen = ref(false)
const selectedContactIndex = ref<number | null>(null)
const savingContactKey = ref<string | null>(null)
const body = computed(() => communicationMessageBody(props.message))
const semanticContent = computed<MessageContent>(() => props.message.content ?? {})
const contacts = computed(() => semanticContent.value.contacts ?? [])
const poll = computed(() => semanticContent.value.poll ?? null)
const pollOptions = computed(() => poll.value?.options ?? [])
const linkPreviewHref = computed(() => {
  const rawUrl = semanticContent.value.link_preview?.url
  if (!rawUrl) return null
  try {
    const parsed = new URL(rawUrl)
    return ['http:', 'https:'].includes(parsed.protocol) ? parsed.toString() : null
  } catch {
    return null
  }
})
const selectedContact = computed<MessageContact | null>(() => {
  const index = selectedContactIndex.value
  return index === null ? null : contacts.value[index] ?? null
})
const availabilityPlaceholder = computed(() => communicationAvailabilityPlaceholder(props.message))
const canRecoverMedia = computed(() => Boolean(
  props.canReply
  && props.message.availability?.recoverable
  && props.message.availability.state !== 'MEDIA_REQUESTED'
))
const stickerAttachment = computed(() => props.message.kind === 'STICKER'
  ? props.message.attachments?.find(attachment => !attachment.purged_at) ?? null
  : null)
const selectableOptions = computed(() => Math.max(
  1,
  Math.min(pollOptions.value.length || 1, poll.value?.selectable_options || 1)
))

const richCardIcon = computed(() => ({
  PRODUCT: 'i-lucide-package',
  ORDER: 'i-lucide-shopping-bag',
  PAYMENT: 'i-lucide-receipt-text',
  EVENT: 'i-lucide-calendar-days',
  CALL: 'i-lucide-phone-call',
  INVITE: 'i-lucide-mail-plus',
  SYSTEM: 'i-lucide-info'
}[semanticContent.value.rich_card?.category || 'SYSTEM'] ?? 'i-lucide-info'))

function attachmentIcon(attachment: Attachment): string {
  if (props.message.kind === 'STICKER') return 'i-lucide-sticker'
  if (attachment.mime_type.startsWith('image/')) return 'i-lucide-image'
  if (attachment.mime_type.startsWith('audio/')) return 'i-lucide-audio-lines'
  if (attachment.mime_type.startsWith('video/')) return 'i-lucide-video'
  return 'i-lucide-file-text'
}

function attachmentSize(attachment: Attachment): string {
  if (attachment.size_bytes < 1024) return `${attachment.size_bytes} B`
  const kilobytes = attachment.size_bytes / 1024
  if (kilobytes < 1024) return `${Math.ceil(kilobytes)} KB`
  return `${(kilobytes / 1024).toFixed(1)} MB`
}

function mediaUrl(url?: string | null): string | undefined {
  return url ? resolveApiUrl(url, apiBase) : undefined
}

function isViewable(attachment: Attachment): boolean {
  return ['image/', 'audio/', 'video/'].some(prefix => attachment.mime_type.startsWith(prefix))
}

function downloadAttachment(attachment: Attachment): void {
  emit(
    'download',
    props.message,
    attachment.id,
    communicationAttachmentFilename(props.message, attachment.id)
  )
}

function openAttachment(attachment: Attachment): void {
  if (!attachment.purged_at && isViewable(attachment)) {
    emit('openMedia', props.message, attachment.id)
  }
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

function requestContactSave(contactIndex: number): void {
  if (!props.canManageContacts) return
  const phones = contacts.value[contactIndex]?.phones ?? []
  if (phones.length === 1) {
    void saveContact(contactIndex, 0)
    return
  }
  selectedContactIndex.value = contactIndex
  contactPhoneOpen.value = true
}

function saveSelectedContact(phoneIndex: number): void {
  if (selectedContactIndex.value === null) return
  void saveContact(selectedContactIndex.value, phoneIndex)
}

async function saveContact(contactIndex: number, phoneIndex: number): Promise<void> {
  const key = `${contactIndex}:${phoneIndex}`
  if (!props.canManageContacts || savingContactKey.value) return
  savingContactKey.value = key
  const api = useApi()
  const toast = useToast()
  try {
    const response = await api.communication.conversations.saveSharedContact(
      props.message.conversation_id,
      props.message.id,
      contactIndex,
      phoneIndex
    )
    contactPhoneOpen.value = false
    selectedContactIndex.value = null
    toast.add({
      title: response.data.outcome === 'created' ? 'Contato salvo' : 'Contato já existente',
      description: response.data.outcome === 'created'
        ? 'O contato compartilhado foi adicionado ao cadastro.'
        : 'O cadastro existente foi mantido, sem duplicação.',
      icon: response.data.outcome === 'created' ? 'i-lucide-user-check' : 'i-lucide-contact',
      color: 'success'
    })
  } catch (caught) {
    toast.add({
      title: 'Não foi possível salvar o contato',
      description: apiErrorMessage(caught, 'Tente novamente após verificar sua conexão e permissão.'),
      color: 'error'
    })
  } finally {
    savingContactKey.value = null
  }
}

function locationUrl(latitude: number, longitude: number): string {
  return `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=17/${latitude}/${longitude}`
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
      <a
        v-if="message.kind === 'LOCATION' && message.content?.location"
        :href="locationUrl(message.content.location.latitude, message.content.location.longitude)"
        target="_blank"
        rel="noopener noreferrer"
        class="block rounded-xl bg-elevated/50 p-3 ring-1 ring-inset ring-default transition-colors hover:bg-elevated focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        data-testid="communication-location-card"
      >
        <div class="flex items-start gap-2.5">
          <UIcon name="i-lucide-map-pin" class="mt-0.5 size-5 shrink-0 text-primary" />
          <div class="min-w-0">
            <p class="break-words font-medium">
              {{ message.content.location.name || 'Localização compartilhada' }}
            </p>
            <p v-if="message.content.location.address" class="mt-0.5 break-words text-xs opacity-80">
              {{ message.content.location.address }}
            </p>
            <p class="mt-1 text-[11px] tabular-nums opacity-70">
              {{ message.content.location.latitude.toFixed(6) }}, {{ message.content.location.longitude.toFixed(6) }}
            </p>
            <span class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary">
              Abrir no mapa <UIcon name="i-lucide-external-link" class="size-3.5" />
            </span>
          </div>
        </div>
      </a>

      <div
        v-else-if="message.kind === 'CONTACT' && contacts.length"
        class="space-y-2"
        data-testid="communication-contact-list"
      >
        <article
          v-for="(contact, contactIndex) in contacts"
          :key="`${contact.display_name || 'contato'}-${contactIndex}`"
          class="rounded-xl bg-elevated/50 p-3 ring-1 ring-inset ring-default"
          data-testid="communication-contact-card"
        >
          <div class="flex min-w-0 items-center gap-3">
            <UAvatar
              :alt="contact.display_name || 'Contato compartilhado'"
              icon="i-lucide-user"
              size="md"
              class="shrink-0"
            />
            <div class="min-w-0 flex-1">
              <p class="break-words font-medium">
                {{ contact.display_name || 'Contato compartilhado' }}
              </p>
              <p v-if="contact.phones?.length" class="mt-0.5 break-all text-xs opacity-75">
                {{ contact.phones[0]?.phone }}<template v-if="contact.phones.length > 1">
                  · +{{ contact.phones.length - 1 }}
                </template>
              </p>
              <p v-else class="mt-0.5 text-xs opacity-75">
                Nenhum telefone válido neste cartão
              </p>
            </div>
            <UButton
              v-if="canManageContacts"
              icon="i-lucide-user-plus"
              color="neutral"
              variant="outline"
              size="xs"
              :label="contacts.length === 1 ? 'Salvar' : undefined"
              :aria-label="`Salvar ${contact.display_name || 'contato compartilhado'}`"
              :disabled="!contact.phones?.length || Boolean(savingContactKey)"
              :loading="savingContactKey?.startsWith(`${contactIndex}:`)"
              @click="requestContactSave(contactIndex)"
            />
          </div>
        </article>
      </div>

      <div
        v-else-if="message.kind === 'POLL' && poll"
        class="rounded-xl bg-elevated/50 p-3 ring-1 ring-inset ring-default"
        data-testid="communication-poll-card"
      >
        <div class="mb-2 flex items-start gap-2">
          <UIcon name="i-lucide-list-checks" class="mt-0.5 size-4 shrink-0 text-primary" />
          <div class="min-w-0">
            <p class="break-words font-medium">
              {{ poll.name || body || 'Enquete' }}
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
            <span class="min-w-0 break-words text-left">{{ option }}</span>
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
        v-else-if="message.kind === 'INTERACTIVE' && message.content?.rich_card"
        class="rounded-xl bg-elevated/50 p-3 ring-1 ring-inset ring-default"
        data-testid="communication-rich-card"
      >
        <div class="flex items-start gap-2.5">
          <UIcon :name="richCardIcon" class="mt-0.5 size-5 shrink-0 text-primary" />
          <div class="min-w-0">
            <p class="break-words font-medium">
              {{ message.content.rich_card.title }}
            </p>
            <p v-if="message.content.rich_card.description" class="mt-1 break-words text-xs opacity-80">
              {{ message.content.rich_card.description }}
            </p>
          </div>
        </div>
        <dl v-if="message.content.rich_card.facts?.length" class="mt-3 grid gap-2 border-t border-default pt-3 text-xs sm:grid-cols-2">
          <div v-for="fact in message.content.rich_card.facts" :key="`${fact.label}:${fact.value}`" class="min-w-0">
            <dt class="text-muted">
              {{ fact.label }}
            </dt>
            <dd class="break-words font-medium text-highlighted">
              {{ fact.value }}
            </dd>
          </div>
        </dl>
        <p class="mt-2 text-[11px] text-muted">
          Somente leitura
        </p>
      </div>

      <div
        v-else-if="message.kind === 'INTERACTIVE' && message.content?.interactive"
        class="rounded-xl bg-elevated/50 p-3 ring-1 ring-inset ring-default"
        data-testid="communication-interactive-card"
      >
        <div class="flex items-start gap-2">
          <UIcon name="i-lucide-mouse-pointer-click" class="mt-0.5 size-4 shrink-0 text-primary" />
          <div class="min-w-0">
            <p class="break-words font-medium">
              {{ message.content.interactive.title || message.content.interactive.name || 'Mensagem interativa' }}
            </p>
            <p v-if="message.content.interactive.description" class="mt-0.5 break-words text-xs opacity-80">
              {{ message.content.interactive.description }}
            </p>
            <p v-if="message.content.interactive.display_text" class="mt-2 break-words text-xs opacity-80">
              {{ message.content.interactive.display_text }}
            </p>
            <p v-if="message.content.interactive_response?.text" class="mt-2 break-words text-xs font-medium">
              Resposta: {{ message.content.interactive_response.text }}
            </p>
          </div>
        </div>
      </div>

      <component
        :is="linkPreviewHref ? 'a' : 'div'"
        v-if="message.content?.link_preview"
        :href="linkPreviewHref || undefined"
        :target="linkPreviewHref ? '_blank' : undefined"
        :rel="linkPreviewHref ? 'noopener noreferrer' : undefined"
        class="mt-2 block max-w-full rounded-xl bg-elevated/50 p-3 ring-1 ring-inset ring-default transition-colors hover:bg-elevated focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        data-testid="communication-link-preview"
      >
        <div class="flex items-start gap-2">
          <UIcon name="i-lucide-link-2" class="mt-0.5 size-4 shrink-0 text-primary" />
          <div class="min-w-0">
            <p class="break-words text-sm font-medium">
              {{ message.content.link_preview.title || message.content.link_preview.url }}
            </p>
            <p v-if="message.content.link_preview.description" class="mt-0.5 line-clamp-3 break-words text-xs opacity-80">
              {{ message.content.link_preview.description }}
            </p>
            <p class="mt-1 truncate text-[11px] text-primary">
              {{ message.content.link_preview.url }}
            </p>
          </div>
        </div>
      </component>

      <div v-if="message.kind === 'STICKER'" class="py-1">
        <button
          v-if="stickerAttachment?.preview_url"
          type="button"
          class="block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          :aria-label="`Abrir ${stickerAttachment.filename}`"
          @click="openAttachment(stickerAttachment)"
        >
          <img
            :src="mediaUrl(stickerAttachment.preview_url)"
            :alt="stickerAttachment.filename"
            class="max-h-48 max-w-48 object-contain"
            loading="lazy"
          >
        </button>
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

      <p v-if="body && message.kind !== 'POLL'" class="whitespace-pre-wrap break-words text-sm">
        {{ body }}
      </p>

      <div v-if="message.kind !== 'STICKER' && message.attachments?.length" class="mt-2 space-y-2">
        <article
          v-for="attachment in message.attachments"
          :key="attachment.id"
          class="overflow-hidden rounded-xl bg-elevated/50 ring-1 ring-inset ring-default"
        >
          <button
            v-if="attachment.preview_url && attachment.mime_type.startsWith('image/') && !attachment.purged_at"
            type="button"
            class="block w-full focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary"
            :aria-label="`Abrir ${attachment.filename}`"
            @click="openAttachment(attachment)"
          >
            <img
              :src="mediaUrl(attachment.preview_url)"
              :alt="attachment.filename"
              class="max-h-80 w-full bg-black/5 object-contain"
              loading="lazy"
            >
          </button>
          <audio
            v-else-if="attachment.preview_url && attachment.mime_type.startsWith('audio/') && !attachment.purged_at"
            :src="mediaUrl(attachment.preview_url)"
            controls
            preload="metadata"
            class="block w-full max-w-full px-2 py-2"
            @play="markAudioPlayed"
          />
          <video
            v-else-if="attachment.preview_url && attachment.mime_type.startsWith('video/') && !attachment.purged_at"
            :src="mediaUrl(attachment.preview_url)"
            controls
            playsinline
            preload="metadata"
            class="max-h-80 w-full bg-black object-contain"
          />

          <div class="flex min-w-0 items-center gap-1 px-2.5 py-2 text-xs">
            <UIcon :name="attachmentIcon(attachment)" class="size-4 shrink-0" />
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ attachment.filename }}</span>
              <span class="block truncate opacity-70">{{ attachment.mime_type }} · {{ attachmentSize(attachment) }}</span>
            </span>
            <UButton
              v-if="isViewable(attachment)"
              icon="i-lucide-maximize-2"
              color="neutral"
              variant="ghost"
              size="xs"
              :aria-label="`Abrir ${attachment.filename} no visualizador`"
              :disabled="!!attachment.purged_at"
              @click="openAttachment(attachment)"
            />
            <UButton
              icon="i-lucide-download"
              color="neutral"
              variant="ghost"
              size="xs"
              :aria-label="`Baixar ${attachment.filename}`"
              :disabled="!!attachment.purged_at"
              @click="downloadAttachment(attachment)"
            />
          </div>
        </article>
      </div>

      <div
        v-if="message.kind === 'UNSUPPORTED'"
        class="mt-2 rounded-xl bg-warning/10 p-3 text-sm ring-1 ring-inset ring-warning/30"
        data-testid="communication-unsupported-card"
      >
        <div class="flex items-start gap-2">
          <UIcon name="i-lucide-circle-alert" class="mt-0.5 size-4 shrink-0 text-warning" />
          <div class="min-w-0">
            <p class="font-medium">
              Mensagem ainda não compatível
            </p>
            <p class="mt-0.5 text-xs opacity-80">
              O conteúdo foi contabilizado com segurança, mas não pode ser exibido nesta versão.
            </p>
            <p v-if="message.provider_type" class="mt-1 break-all text-[11px] opacity-70">
              Tipo: {{ message.provider_type }}
            </p>
          </div>
        </div>
      </div>

      <div
        v-else-if="availabilityPlaceholder"
        class="mt-2 flex items-start gap-2 rounded-xl bg-elevated/50 px-2.5 py-2 text-sm ring-1 ring-inset ring-default"
        :data-testid="`communication-message-availability-${message.availability?.state || 'UNAVAILABLE'}`"
      >
        <UIcon name="i-lucide-circle-alert" class="mt-0.5 size-4 shrink-0" />
        <span>{{ availabilityPlaceholder }}</span>
      </div>

      <div v-if="message.metadata?.media_state || message.metadata?.view_once || message.metadata?.history || message.content?.ptt || message.content?.gif" class="mt-2 flex flex-wrap gap-1">
        <UBadge
          v-if="message.content?.ptt"
          label="Mensagem de voz"
          color="info"
          variant="subtle"
        />
        <UBadge
          v-if="message.content?.gif"
          label="GIF"
          color="info"
          variant="subtle"
        />
        <UBadge
          v-if="message.metadata?.view_once"
          label="Visualização única"
          color="warning"
          variant="subtle"
        />
        <UBadge
          v-if="message.metadata?.history"
          label="Histórico importado"
          color="neutral"
          variant="subtle"
        />
        <UBadge
          v-if="message.metadata?.media_state"
          :label="`Mídia: ${message.metadata.media_state}`"
          :color="message.metadata.media_state === 'FAILED' ? 'error' : 'neutral'"
          variant="subtle"
        />
      </div>

      <div v-if="canRecoverMedia" class="mt-2 flex flex-wrap gap-1">
        <UButton
          label="Recuperar mídia"
          icon="i-lucide-refresh-cw"
          color="warning"
          variant="ghost"
          size="xs"
          :loading="actionLoading"
          @click="emit('recover', message, 'MEDIA_RETRY')"
        />
      </div>
    </template>
  </div>

  <UModal
    v-model:open="contactPhoneOpen"
    title="Escolher telefone"
    description="O nome e o telefone serão resolvidos novamente no servidor a partir desta mensagem."
  >
    <template #body>
      <div class="space-y-2">
        <p class="break-words text-sm font-medium text-highlighted">
          {{ selectedContact?.display_name || 'Contato compartilhado' }}
        </p>
        <UButton
          v-for="(phone, phoneIndex) in selectedContact?.phones || []"
          :key="`${phone.phone}:${phoneIndex}`"
          block
          color="neutral"
          variant="outline"
          class="justify-between"
          :loading="savingContactKey === `${selectedContactIndex}:${phoneIndex}`"
          :disabled="Boolean(savingContactKey)"
          @click="saveSelectedContact(phoneIndex)"
        >
          <span class="truncate">{{ phone.label || 'Telefone' }}</span>
          <span class="shrink-0 tabular-nums">{{ phone.phone }}</span>
        </UButton>
      </div>
    </template>
  </UModal>
</template>
