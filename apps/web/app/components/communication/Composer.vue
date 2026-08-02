<script setup lang="ts">
import type { CannedResponse } from '~/types/communication/quick-responses'
import type { ComposerPayload, Message, SendKind } from '~/types/communication/messages'
import { communicationMessageSummary } from '~/utils/communication'
import {
  COMMUNICATION_REACTION_EMOJIS,
  communicationRecordingExtension,
  communicationSendKindForMime,
  formatCommunicationRecordingDuration,
  preferredCommunicationRecorderMimeType,
  shouldSubmitCommunicationComposer
} from '~/utils/communication-composer'
import {
  filterCannedResponsesByShortcut,
  findCannedSlashToken,
  replaceCannedSlashToken,
  shouldHandleCannedAutocompleteKey,
  type CannedSlashTokenMatch
} from '~/utils/communication-quick-responses'
import { apiErrorMessage } from '~/utils/api-error'

const MAX_RECORDING_SECONDS = 120
const MAX_MEDIA_BYTES = 20 * 1024 * 1024

const props = defineProps<{
  canReply: boolean
  operational: boolean
  outboundOperational: boolean
  unavailableReason?: string
  sending?: boolean
  conversationId?: number | null
  cannedResponses: CannedResponse[]
  replyTo?: Message | null
}>()

const emit = defineEmits<{
  send: [payload: ComposerPayload, acknowledge: (ok: boolean) => void]
  cancelReply: []
  presence: [presence: 'COMPOSING' | 'PAUSED' | 'RECORDING']
}>()

const api = useApi()
const toast = useToast()
const body = ref('')
const internalNote = ref(false)
const file = ref<File | null>(null)
const fileKind = ref<SendKind>('TEXT')
const ptt = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const stickerInput = ref<HTMLInputElement | null>(null)
const selectedCannedId = ref<number | undefined>()
const recording = ref(false)
const recordingSeconds = ref(0)
const cursor = ref(0)
const composingIme = ref(false)
const autocompleteOpen = ref(false)
const autocompleteIndex = ref(0)
const composerRoot = ref<HTMLElement | null>(null)
const autocompleteToken = ref<CannedSlashTokenMatch | null>(null)
const insertingCanned = ref(false)
const listboxId = 'communication-composer-canned-listbox'
let pauseTimer: ReturnType<typeof setTimeout> | null = null
let recordingTimer: ReturnType<typeof setInterval> | null = null
let mediaRecorder: MediaRecorder | null = null
let mediaStream: MediaStream | null = null
let recordingChunks: Blob[] = []
let discardRecordedAudio = false

const cannedItems = computed(() => props.cannedResponses.map(item => ({
  label: `${item.shortcut} · ${item.title}`,
  value: item.id
})))
const autocompleteItems = computed(() => {
  if (!autocompleteOpen.value || !autocompleteToken.value) return []
  return filterCannedResponsesByShortcut(props.cannedResponses, autocompleteToken.value.query)
})
const activeCannedOptionId = computed(() => {
  if (!autocompleteOpen.value) return undefined
  const item = autocompleteItems.value[autocompleteIndex.value]
  return item ? `${listboxId}-option-${item.id}` : undefined
})
const available = computed(() => props.canReply
  && props.operational
  && (internalNote.value || props.outboundOperational))
const hasContent = computed(() => internalNote.value ? Boolean(body.value.trim()) : Boolean(body.value.trim() || file.value))
const unavailableReason = computed(() => {
  if (!props.canReply) return 'Seu perfil possui acesso somente para leitura.'
  if (!props.operational) return props.unavailableReason || 'A comunicação não está disponível.'
  if (!internalNote.value && !props.outboundOperational) {
    return props.unavailableReason || 'A sessão precisa estar conectada para enviar ao WhatsApp.'
  }
  return ''
})

watch(selectedCannedId, (id) => {
  const selected = props.cannedResponses.find(item => item.id === id)
  if (selected) body.value = selected.body
})

watch(autocompleteItems, (items) => {
  if (!items.length) {
    autocompleteIndex.value = 0
    return
  }
  if (autocompleteIndex.value >= items.length) {
    autocompleteIndex.value = items.length - 1
  }
})

watch(internalNote, (enabled) => {
  if (!enabled) return
  cancelRecording()
  pausePresence()
  clearFile()
  closeAutocomplete()
})

function pausePresence() {
  if (pauseTimer) clearTimeout(pauseTimer)
  pauseTimer = null
  if (!internalNote.value) emit('presence', 'PAUSED')
}

function announceComposing() {
  if (internalNote.value || !available.value || recording.value) return
  emit('presence', 'COMPOSING')
  if (pauseTimer) clearTimeout(pauseTimer)
  pauseTimer = setTimeout(pausePresence, 8_000)
}

function closeAutocomplete() {
  autocompleteOpen.value = false
  autocompleteToken.value = null
  autocompleteIndex.value = 0
}

function syncCursorFromTarget(target: EventTarget | null) {
  if (!(target instanceof HTMLTextAreaElement)) return
  cursor.value = target.selectionStart ?? body.value.length
}

function refreshAutocomplete() {
  if (composingIme.value || !available.value || recording.value || props.sending) {
    closeAutocomplete()
    return
  }
  const match = findCannedSlashToken(body.value, cursor.value)
  if (!match) {
    closeAutocomplete()
    return
  }
  autocompleteToken.value = match
  autocompleteOpen.value = true
  autocompleteIndex.value = 0
}

function onBodyInput(event: Event) {
  syncCursorFromTarget(event.target)
  announceComposing()
  refreshAutocomplete()
}

function onBodySelect(event: Event) {
  syncCursorFromTarget(event.target)
  refreshAutocomplete()
}

function onCompositionStart() {
  composingIme.value = true
}

function onCompositionEnd(event: Event) {
  composingIme.value = false
  syncCursorFromTarget(event.target)
  refreshAutocomplete()
}

async function applyCannedResponse(item: CannedResponse) {
  const match = autocompleteToken.value ?? findCannedSlashToken(body.value, cursor.value)
  if (!match || insertingCanned.value) return
  insertingCanned.value = true
  try {
    let text = item.body
    if (props.conversationId) {
      try {
        const rendered = await api.communication.catalog.renderCannedResponse(item.id, {
          conversation_id: props.conversationId
        })
        text = rendered.data.body
      } catch (caught) {
        toast.add({
          title: apiErrorMessage(caught, 'Não foi possível renderizar a resposta rápida.'),
          color: 'warning'
        })
      }
    }
    body.value = replaceCannedSlashToken(body.value, match, text)
    cursor.value = match.start + text.length
    closeAutocomplete()
    selectedCannedId.value = undefined
    announceComposing()
  } finally {
    insertingCanned.value = false
  }
}

function onComposerKeydown(event: KeyboardEvent) {
  if (autocompleteOpen.value && autocompleteItems.value.length) {
    if (shouldHandleCannedAutocompleteKey(event)) {
      if (event.key === 'Escape') {
        event.preventDefault()
        closeAutocomplete()
        return
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault()
        autocompleteIndex.value = (autocompleteIndex.value + 1) % autocompleteItems.value.length
        return
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault()
        autocompleteIndex.value = (autocompleteIndex.value - 1 + autocompleteItems.value.length)
          % autocompleteItems.value.length
        return
      }
      if (event.key === 'Enter' || event.key === 'Tab') {
        const selected = autocompleteItems.value[autocompleteIndex.value]
        if (selected) {
          event.preventDefault()
          void applyCannedResponse(selected)
          return
        }
      }
    }
  }

  if (!shouldSubmitCommunicationComposer(event)) return
  event.preventDefault()
  submit()
}

function chooseFile() {
  if (!available.value || internalNote.value || recording.value) return
  fileInput.value?.click()
}

function chooseSticker() {
  if (!available.value || internalNote.value || recording.value) return
  stickerInput.value?.click()
}

function attachFile(next: File, kind: SendKind, asPtt = false) {
  if (next.size > MAX_MEDIA_BYTES) {
    toast.add({ title: 'O arquivo excede o limite de 20 MB.', color: 'warning' })
    return
  }
  file.value = next
  fileKind.value = kind
  ptt.value = asPtt
}

function onFile(event: Event) {
  const input = event.target as HTMLInputElement
  const selected = input.files?.[0]
  if (selected) attachFile(selected, communicationSendKindForMime(selected.type))
}

function onSticker(event: Event) {
  const input = event.target as HTMLInputElement
  const selected = input.files?.[0]
  if (!selected) return
  if (selected.type.toLowerCase() !== 'image/webp' && !selected.name.toLowerCase().endsWith('.webp')) {
    toast.add({ title: 'Selecione um sticker no formato WebP.', color: 'warning' })
    input.value = ''
    return
  }
  attachFile(selected, 'STICKER')
}

function clearFile() {
  file.value = null
  fileKind.value = 'TEXT'
  ptt.value = false
  if (fileInput.value) fileInput.value.value = ''
  if (stickerInput.value) stickerInput.value.value = ''
}

function clearDraft() {
  body.value = ''
  selectedCannedId.value = undefined
  clearFile()
  closeAutocomplete()
  emit('cancelReply')
}

function insertEmoji(emoji: string) {
  body.value += emoji
  announceComposing()
}

function submit() {
  if (!available.value || !hasContent.value || props.sending || recording.value || insertingCanned.value) return
  pausePresence()
  emit('send', {
    body: body.value.trim(),
    internalNote: internalNote.value,
    replyToMessageId: props.replyTo?.id ?? null,
    file: file.value,
    kind: file.value ? fileKind.value : 'TEXT',
    ptt: Boolean(file.value && ptt.value)
  }, (ok) => {
    if (ok) clearDraft()
  })
}

function stopMediaTracks() {
  for (const track of mediaStream?.getTracks() ?? []) track.stop()
  mediaStream = null
}

function clearRecordingTimer() {
  if (recordingTimer) clearInterval(recordingTimer)
  recordingTimer = null
}

function finishRecorder(discard: boolean) {
  if (!mediaRecorder || mediaRecorder.state === 'inactive') return
  discardRecordedAudio = discard
  mediaRecorder.stop()
}

function cancelRecording() {
  if (!recording.value) return
  finishRecorder(true)
}

function finishRecording() {
  if (!recording.value) return
  finishRecorder(false)
}

async function startRecording() {
  if (!available.value || internalNote.value || props.sending || recording.value) return
  const Recorder = globalThis.MediaRecorder
  if (!Recorder || !navigator.mediaDevices?.getUserMedia) {
    toast.add({ title: 'Este navegador não oferece gravação de áudio compatível.', color: 'warning' })
    return
  }
  const mimeType = preferredCommunicationRecorderMimeType(type => Recorder.isTypeSupported(type))
  if (!mimeType) {
    toast.add({ title: 'Nenhum formato de áudio compatível foi encontrado.', color: 'warning' })
    return
  }
  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true })
    mediaRecorder = new Recorder(mediaStream, { mimeType })
    recordingChunks = []
    discardRecordedAudio = false
    mediaRecorder.ondataavailable = (event) => {
      if (event.data.size > 0) recordingChunks.push(event.data)
    }
    mediaRecorder.onerror = () => {
      toast.add({ title: 'A gravação de áudio foi interrompida.', color: 'error' })
      discardRecordedAudio = true
    }
    mediaRecorder.onstop = () => {
      clearRecordingTimer()
      stopMediaTracks()
      recording.value = false
      emit('presence', 'PAUSED')
      const chunks = recordingChunks
      recordingChunks = []
      const discard = discardRecordedAudio
      discardRecordedAudio = false
      if (discard || !chunks.length) return
      const blob = new Blob(chunks, { type: mimeType })
      if (blob.size > MAX_MEDIA_BYTES) {
        toast.add({ title: 'A gravação excedeu o limite de 20 MB.', color: 'warning' })
        return
      }
      const extension = communicationRecordingExtension(mimeType)
      attachFile(new File([blob], `audio-${Date.now()}.${extension}`, { type: mimeType }), 'AUDIO', true)
    }
    clearFile()
    recordingSeconds.value = 0
    recording.value = true
    emit('presence', 'RECORDING')
    mediaRecorder.start(250)
    recordingTimer = setInterval(() => {
      recordingSeconds.value += 1
      if (recordingSeconds.value >= MAX_RECORDING_SECONDS) finishRecording()
    }, 1_000)
  } catch {
    stopMediaTracks()
    recording.value = false
    toast.add({ title: 'Não foi possível acessar o microfone.', color: 'error' })
  }
}

onBeforeUnmount(() => {
  if (pauseTimer) clearTimeout(pauseTimer)
  if (recording.value) finishRecorder(true)
  clearRecordingTimer()
  stopMediaTracks()
})

async function focusInput(): Promise<boolean> {
  await nextTick()
  const markedElement = composerRoot.value?.querySelector('[data-communication-message-input]')
  const input = markedElement instanceof HTMLTextAreaElement
    ? markedElement
    : markedElement?.querySelector('textarea')
  if (!(input instanceof HTMLTextAreaElement)
    || markedElement?.getAttribute('aria-disabled') === 'true'
    || markedElement?.hasAttribute('disabled')
    || input.getAttribute('aria-disabled') === 'true'
    || input.hasAttribute('disabled')) return false
  input.focus({ preventScroll: true })
  return document.activeElement === input
}

defineExpose({ focusInput })
</script>

<template>
  <div
    ref="composerRoot"
    data-testid="communication-composer"
    class="shrink-0 border-t border-default bg-default/95 p-3 backdrop-blur sm:px-5 sm:py-4"
  >
    <UAlert
      v-if="unavailableReason"
      :title="unavailableReason"
      icon="i-lucide-shield-alert"
      color="warning"
      variant="subtle"
      class="mb-3"
    />

    <div
      class="rounded-xl border bg-elevated/35 p-2.5 shadow-xs transition-colors sm:p-3"
      :class="internalNote ? 'border-warning/50' : 'border-default focus-within:border-primary/50'"
    >
      <div
        v-if="replyTo"
        class="mb-2 flex items-start justify-between gap-2 rounded-lg border-l-2 border-primary bg-primary/5 px-3 py-2 text-xs"
      >
        <div class="min-w-0">
          <p class="font-medium text-highlighted">
            Respondendo mensagem
          </p>
          <p class="line-clamp-2 text-muted">
            {{ communicationMessageSummary(replyTo) }}
          </p>
        </div>
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="xs"
          aria-label="Remover citação"
          @click="emit('cancelReply')"
        />
      </div>

      <div class="mb-1.5 flex items-center gap-2">
        <USwitch
          v-model="internalNote"
          label="Nota interna"
          size="sm"
          :disabled="!canReply || sending"
        />
        <USelectMenu
          v-model="selectedCannedId"
          :items="cannedItems"
          value-key="value"
          placeholder="Resposta pronta"
          size="sm"
          class="ml-auto min-w-0 max-w-52 flex-1"
          data-testid="communication-composer-canned-touch"
          :disabled="!available || sending || recording"
        />
      </div>

      <div class="relative">
        <UTextarea
          v-model="body"
          data-communication-message-input
          :placeholder="internalNote ? 'Nota visível apenas para a equipe…' : 'Digite uma mensagem ou /atalho'"
          :rows="2"
          autoresize
          :maxrows="7"
          variant="none"
          class="w-full"
          :ui="{ base: 'min-h-12 resize-none px-1 py-2 text-sm' }"
          :disabled="!available || sending || recording"
          role="combobox"
          :aria-expanded="autocompleteOpen"
          :aria-controls="autocompleteOpen ? listboxId : undefined"
          :aria-autocomplete="autocompleteOpen ? 'list' : undefined"
          :aria-activedescendant="activeCannedOptionId"
          @input="onBodyInput"
          @select="onBodySelect"
          @click="onBodySelect"
          @keyup="onBodySelect"
          @compositionstart="onCompositionStart"
          @compositionend="onCompositionEnd"
          @focus="announceComposing"
          @blur="pausePresence"
          @keydown="onComposerKeydown"
        />

        <ul
          v-if="autocompleteOpen && autocompleteItems.length"
          :id="listboxId"
          role="listbox"
          data-testid="communication-composer-canned-listbox"
          aria-label="Respostas rápidas"
          class="absolute inset-x-0 bottom-full z-20 mb-1 max-h-56 overflow-auto rounded-lg border border-default bg-default py-1 shadow-lg"
        >
          <li
            v-for="(item, index) in autocompleteItems"
            :id="`${listboxId}-option-${item.id}`"
            :key="item.id"
            role="option"
            :aria-selected="index === autocompleteIndex"
            class="cursor-pointer px-3 py-2 text-sm"
            :class="index === autocompleteIndex ? 'bg-elevated text-highlighted' : 'text-default hover:bg-elevated/70'"
            @mousedown.prevent="applyCannedResponse(item)"
          >
            <p class="font-mono text-xs text-muted">
              /{{ item.shortcut }}
            </p>
            <p class="truncate font-medium">
              {{ item.title }}
            </p>
          </li>
        </ul>
      </div>

      <div
        v-if="recording"
        data-testid="communication-audio-recording"
        class="mb-2 flex items-center gap-3 rounded-lg bg-error/10 px-3 py-2 text-sm text-error"
      >
        <span class="size-2 animate-pulse rounded-full bg-error" />
        <span class="font-medium">Gravando {{ formatCommunicationRecordingDuration(recordingSeconds) }}</span>
        <span class="hidden text-xs text-muted sm:inline">limite de 2 minutos</span>
        <div class="ml-auto flex items-center gap-1">
          <UButton
            icon="i-lucide-trash-2"
            color="error"
            variant="ghost"
            size="sm"
            aria-label="Cancelar gravação"
            @click="cancelRecording"
          />
          <UButton
            label="Concluir"
            icon="i-lucide-square"
            color="error"
            variant="soft"
            size="sm"
            @click="finishRecording"
          />
        </div>
      </div>

      <div
        v-else-if="file"
        class="mb-2 flex min-w-0 items-center gap-2 rounded-lg bg-elevated px-3 py-2 text-xs"
      >
        <UIcon
          :name="fileKind === 'STICKER' ? 'i-lucide-sticker' : fileKind === 'AUDIO' ? 'i-lucide-audio-lines' : 'i-lucide-paperclip'"
          class="size-4 shrink-0 text-primary"
        />
        <div class="min-w-0 flex-1">
          <p class="truncate font-medium text-highlighted">
            {{ file.name }}
          </p>
          <p class="text-muted">
            {{ fileKind === 'STICKER' ? 'Sticker WebP' : ptt ? 'Mensagem de voz' : `${Math.ceil(file.size / 1024)} KB` }}
          </p>
        </div>
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="xs"
          aria-label="Remover anexo"
          @click="clearFile"
        />
      </div>

      <div class="flex min-w-0 items-center gap-0.5 border-t border-default/70 pt-2">
        <template v-if="!internalNote">
          <input
            ref="fileInput"
            type="file"
            class="sr-only"
            accept="image/jpeg,image/png,image/webp,audio/ogg,audio/mpeg,audio/mp4,audio/webm,video/mp4,application/pdf,text/plain,application/zip"
            @change="onFile"
          >
          <input
            ref="stickerInput"
            type="file"
            class="sr-only"
            accept="image/webp,.webp"
            @change="onSticker"
          >
          <UTooltip text="Anexar mídia ou documento (até 20 MB)">
            <UButton
              icon="i-lucide-paperclip"
              color="neutral"
              variant="ghost"
              :disabled="!available || sending || recording"
              aria-label="Anexar arquivo"
              @click="chooseFile"
            />
          </UTooltip>
          <UTooltip text="Enviar sticker WebP">
            <UButton
              icon="i-lucide-sticker"
              color="neutral"
              variant="ghost"
              :disabled="!available || sending || recording"
              aria-label="Selecionar sticker"
              @click="chooseSticker"
            />
          </UTooltip>
          <UPopover>
            <UTooltip text="Adicionar emoji">
              <UButton
                icon="i-lucide-smile-plus"
                color="neutral"
                variant="ghost"
                :disabled="!available || sending || recording"
                aria-label="Adicionar emoji"
              />
            </UTooltip>
            <template #content>
              <div class="grid w-64 grid-cols-8 gap-1 p-2" aria-label="Emojis disponíveis">
                <UButton
                  v-for="emoji in COMMUNICATION_REACTION_EMOJIS"
                  :key="emoji"
                  :label="emoji"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  :aria-label="`Inserir ${emoji}`"
                  @click="insertEmoji(emoji)"
                />
              </div>
            </template>
          </UPopover>
          <UTooltip text="Gravar mensagem de voz">
            <UButton
              icon="i-lucide-mic"
              :color="recording ? 'error' : 'neutral'"
              variant="ghost"
              :disabled="!available || sending || Boolean(file)"
              aria-label="Gravar mensagem de voz"
              @click="startRecording"
            />
          </UTooltip>
        </template>

        <span class="ml-1 hidden text-[11px] text-dimmed sm:inline">
          Enter envia · Shift+Enter quebra linha · /atalho
        </span>
        <UButton
          class="ml-auto"
          :label="internalNote ? 'Adicionar nota' : 'Enviar'"
          :icon="internalNote ? 'i-lucide-sticky-note' : 'i-lucide-send'"
          :color="internalNote ? 'warning' : 'primary'"
          size="sm"
          :loading="sending"
          :disabled="!available || !hasContent || recording"
          @click="submit"
        />
      </div>
    </div>
  </div>
</template>
