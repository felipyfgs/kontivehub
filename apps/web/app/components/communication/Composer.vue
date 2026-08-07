<script setup lang="ts">
import type { CannedResponse } from '~/types/communication/quick-responses'
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import { onBeforeRouteLeave } from 'vue-router'
import type { Message, SendKind } from '~/types/communication/messages'
import type { OutboundCapabilities } from '~/types/communication/conversations'
import type {
  ComposerBatchSubmissionKeys,
  ComposerDestinationContext,
  ComposerDraft,
  ComposerDraftContext,
  ComposerDraftFamily,
  ComposerMediaItem,
  ComposerMediaKind
} from '~/types/communication/composer-draft'
import type {
  ComposerLifecycleItem,
  ComposerLifecycleState
} from '~/types/communication/composer-lifecycle'
import type { Contact } from '~/types/communication/contacts'
import type { StickerLibraryFilter, StickerLibraryItem } from '~/types/communication/sticker-library'
import ComposerExpressionPicker from './ComposerExpressionPicker.vue'
import ComposerAttachmentDrawer from './ComposerAttachmentDrawer.vue'
import ComposerMediaLifecycle from './ComposerMediaLifecycle.vue'
import ComposerStickerEditor from './ComposerStickerEditor.vue'
import type { ComposerGifResult } from './ComposerExpressionPicker.vue'
import { communicationMessageSummary } from '~/utils/communication'
import {
  formatCommunicationRecordingDuration,
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
import {
  composerCapability,
  composerCapabilityVariant,
  composerMediaDraftCapability,
  type ComposerCapability
} from '~/utils/communication-composer-capabilities'
import {
  COMPOSER_SESSION_BINARY_BUDGET_BYTES,
  composerDraftBinaryBytes,
  composerDraftHasBinary,
  composerSessionBinaryBytes,
  createComposerBatchSubmissionKeys,
  validateComposerDraft
} from '~/utils/communication-composer-draft'
import {
  composerMediaPreviewKind,
  revokeComposerMediaPreviewUrls,
  syncComposerMediaPreviewUrls
} from '~/utils/communication-composer-media-preview'
import {
  composerLauncherGroups,
  type ComposerLauncherAction,
  type ComposerLauncherGroup
} from '~/utils/communication-composer-launcher'
import { composerContactVCard } from '~/utils/communication-composer-contacts'
import { isPrivateGifResult } from '~/utils/communication-expression-picker'
import { useCommunicationCamera } from '~/composables/useCommunicationCamera'
import { useCommunicationVoiceRecorder } from '~/composables/useCommunicationVoiceRecorder'
import { useCommunicationStickerLibrary } from '~/composables/useCommunicationStickerLibrary'

const MAX_MEDIA_BYTES = 20 * 1024 * 1024

const props = defineProps<{
  canReply: boolean
  operational: boolean
  outboundOperational: boolean
  unavailableReason?: string
  sending?: boolean
  conversationId?: number | null
  inboxId?: number | null
  destinationContext?: ComposerDestinationContext
  cannedResponses: CannedResponse[]
  replyTo?: Message | null
}>()

const emit = defineEmits<{
  send: [payload: ComposerDraft, acknowledge: (ok: boolean, messages?: Message[]) => void]
  cancelReply: []
  restoreReply: [messageId: number | null]
  presence: [presence: 'COMPOSING' | 'PAUSED' | 'RECORDING']
}>()

const api = useApi()
const toast = useToast()
const { user } = useSanctumAuth<{ current_tenant?: { id?: number } | null }>()
const draftStore = useCommunicationComposerDrafts()
const stickerLibraryInboxId = computed(() => props.inboxId ?? null)
const stickerLibrary = useCommunicationStickerLibrary(stickerLibraryInboxId)
const capabilities = ref<OutboundCapabilities | null>(null)
const capabilitiesLoading = ref(false)
const capabilityError = ref<string | null>(null)
const body = ref('')
const internalNote = ref(false)
const file = ref<File | null>(null)
const libraryStickerId = ref<string | null>(null)
const fileKind = ref<SendKind>('TEXT')
const mediaItems = ref<ComposerMediaItem[]>([])
const mediaPreviewUrls = ref(new Map<string, string>())
const structuredEditorError = ref<string | null>(null)
const attachmentTrigger = ref<{ $el?: HTMLElement } | HTMLElement | null>(null)
const ptt = ref(false)
const sensitiveConfirmed = ref(false)
const submission = ref<ComposerBatchSubmissionKeys>(createComposerBatchSubmissionKeys())
const citationId = ref<number | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const stickerInput = ref<HTMLInputElement | null>(null)
const selectedCannedId = ref<number | undefined>()
const cursor = ref(0)
const composingIme = ref(false)
const autocompleteOpen = ref(false)
const autocompleteIndex = ref(0)
const composerRoot = ref<HTMLElement | null>(null)
const autocompleteToken = ref<CannedSlashTokenMatch | null>(null)
const insertingCanned = ref(false)
const launcherGroup = ref<ComposerLauncherGroup | null>(null)
const attachmentOpen = ref(false)
const expressionOpen = ref(false)
const stickerEditorOpen = ref(false)
const drawerOpen = ref(false)
const fileSelectionMode = ref<'MEDIA' | 'DOCUMENT'>('MEDIA')
const breakpoints = useBreakpoints(breakpointsTailwind)
const mobileLauncher = breakpoints.smaller('lg')
const structuredFamily = ref<Extract<
  ComposerDraftFamily,
  'LOCATION' | 'POLL' | 'EVENT' | 'INTERACTIVE'
> | null>(null)
const structuredEditorOpen = ref(false)
const contactsEditorOpen = ref(false)
const contactChoices = ref<Contact[]>([])
const selectedContacts = ref<Contact[]>([])
const contactsDraft = ref<Array<{ displayName: string, vcard: string }>>([])
const contactsQuery = ref('')
const contactsLoading = ref(false)
const contactsError = ref<string | null>(null)
const camera = useCommunicationCamera()
const cameraOpen = ref(false)
const cameraVideo = ref<HTMLVideoElement | null>(null)
const location = ref({ latitude: '', longitude: '', name: '', address: '' })
const poll = ref({ name: '', options: ['', ''], selectableOptions: 1 })
const eventDraft = ref({
  title: '',
  description: '',
  startsAt: '',
  endsAt: '',
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Sao_Paulo',
  location: ''
})
const interactive = ref<{
  type: 'BUTTONS' | 'LIST'
  title: string
  body: string
  actions: string[]
}>({
  type: 'BUTTONS',
  title: '',
  body: '',
  actions: ['', '']
})
const lifecycleItems = ref<ComposerLifecycleItem[]>([])
const lifecyclePreviousStates = ref<Record<string, ComposerLifecycleState | null>>({})
const submitting = ref(false)
const gifSelectionError = ref<string | null>(null)
const gifSelectionLoadingId = ref<string | null>(null)
const stickerLibraryError = ref<string | null>(null)
const stickerSelectionLoadingId = ref<string | null>(null)
const expressionTrigger = ref<{ $el?: HTMLElement } | HTMLElement | null>(null)
const listboxId = 'communication-composer-canned-listbox'
let pauseTimer: ReturnType<typeof setTimeout> | null = null
let contactsRequestEpoch = 0
let contactsRequestTimer: ReturnType<typeof setTimeout> | null = null
let capabilityRequestEpoch = 0
let submissionEpoch = 0
let suspendPersistence = false

const draftContext = computed<ComposerDraftContext | null>(() => {
  const tenantId = user.value?.current_tenant?.id
  if (!tenantId || !props.inboxId || !props.conversationId) return null
  return { tenantId, inboxId: props.inboxId, conversationId: props.conversationId }
})

const maxRecordingSeconds = computed(() => (
  composerCapability(capabilities.value, 'AUDIO').maxDurationSeconds ?? 120
))
const voiceRecorder = useCommunicationVoiceRecorder({
  get maxBytes() {
    return composerCapability(capabilities.value, 'AUDIO').maxBytes ?? MAX_MEDIA_BYTES
  },
  get maxDurationSeconds() {
    return maxRecordingSeconds.value
  }
})
const recording = computed(() => (
  voiceRecorder.state.value === 'recording' || voiceRecorder.state.value === 'paused'
))
const voiceSurfaceOpen = computed(() => voiceRecorder.state.value !== 'idle')
const contactMaxItems = computed(() => {
  const capability = composerCapability(capabilities.value, 'CONTACTS')
  return capability.variants.multiple?.enabled ? capability.maxItems ?? 1 : 1
})

function sameContext(
  left: ComposerDraftContext | null,
  right: ComposerDraftContext | null
): boolean {
  return Boolean(left && right
    && left.tenantId === right.tenantId
    && left.inboxId === right.inboxId
    && left.conversationId === right.conversationId)
}

function draftCitation() {
  return citationId.value ? { replyToMessageId: citationId.value } : null
}

function currentDraftForChannel(channel: 'WHATSAPP' | 'INTERNAL_NOTE'): ComposerDraft {
  if (channel === 'INTERNAL_NOTE') {
    return {
      channel,
      family: 'TEXT',
      body: body.value,
      citation: draftCitation()
    }
  }

  const base = {
    channel: 'WHATSAPP' as const,
    citation: draftCitation(),
    submission: submission.value
  }
  if (mediaItems.value.length) {
    return {
      ...base,
      family: 'MEDIA_BATCH',
      items: mediaItems.value,
      sensitiveConfirmed: sensitiveConfirmed.value
    }
  }
  if (structuredFamily.value === 'LOCATION') {
    return {
      ...base,
      family: 'LOCATION',
      location: {
        latitude: Number(location.value.latitude),
        longitude: Number(location.value.longitude),
        ...(location.value.name && { name: location.value.name }),
        ...(location.value.address && { address: location.value.address })
      }
    }
  }
  if (structuredFamily.value === 'POLL') {
    return {
      ...base,
      family: 'POLL',
      poll: {
        name: poll.value.name,
        options: poll.value.options.filter(Boolean),
        selectableOptions: poll.value.selectableOptions
      }
    }
  }
  if (structuredFamily.value === 'EVENT') {
    return {
      ...base,
      family: 'EVENT',
      event: {
        title: eventDraft.value.title,
        ...(eventDraft.value.description && { description: eventDraft.value.description }),
        startsAt: eventDraft.value.startsAt,
        endsAt: eventDraft.value.endsAt,
        timezone: eventDraft.value.timezone,
        ...(eventDraft.value.location && { location: eventDraft.value.location })
      }
    }
  }
  if (structuredFamily.value === 'INTERACTIVE') {
    return {
      ...base,
      family: 'INTERACTIVE',
      interactive: {
        type: interactive.value.type,
        title: interactive.value.title,
        body: interactive.value.body,
        actions: interactive.value.actions
          .filter(Boolean)
          .map((title, index) => ({ id: `action-${index + 1}`, title }))
      }
    }
  }
  if (contactsDraft.value.length) {
    return { ...base, family: 'CONTACTS', contacts: contactsDraft.value }
  }
  if (voiceRecorder.recorded.value) {
    return {
      ...base,
      family: 'AUDIO',
      file: voiceRecorder.recorded.value.file,
      ptt: voiceRecorder.recorded.value.ptt
    }
  }
  if (file.value && fileKind.value === 'AUDIO') {
    return { ...base, family: 'AUDIO', file: file.value, ptt: ptt.value }
  }
  if ((file.value || libraryStickerId.value) && fileKind.value === 'STICKER') {
    return {
      ...base,
      family: 'STICKER',
      file: file.value,
      libraryStickerId: libraryStickerId.value
    }
  }
  return { ...base, family: 'TEXT', body: body.value }
}

function currentDraft(): ComposerDraft {
  return currentDraftForChannel(internalNote.value ? 'INTERNAL_NOTE' : 'WHATSAPP')
}

function draftHasContent(draft: ComposerDraft): boolean {
  if (draft.channel === 'INTERNAL_NOTE') return false
  if (draft.family === 'TEXT') return Boolean(draft.body.trim())
  if (draft.family === 'MEDIA_BATCH') return draft.items.length > 0
  if (draft.family === 'AUDIO') return Boolean(draft.file)
  if (draft.family === 'STICKER') return Boolean(draft.file || draft.libraryStickerId)
  if (draft.family === 'CONTACTS') return draft.contacts.length > 0
  return true
}

function capabilityForDraft(draft: ComposerDraft): ComposerCapability | null {
  if (draft.channel === 'INTERNAL_NOTE') return null
  if (draft.family === 'MEDIA_BATCH') {
    return composerMediaDraftCapability(capabilities.value, draft.items)
  }
  const capability = composerCapability(capabilities.value, draft.family)
  if (!capability.enabled) return capability
  if (draft.family === 'AUDIO' && draft.ptt) {
    const variant = composerCapabilityVariant(capabilities.value, 'AUDIO', 'ptt')
    if (!variant.enabled) return { ...capability, enabled: false, reason: variant.reason }
  }
  if (draft.family === 'STICKER' && draft.libraryStickerId) {
    const library = composerCapabilityVariant(capabilities.value, 'STICKER', 'library')
    if (!library.enabled) {
      return { ...capability, enabled: false, reason: library.reason || 'Biblioteca de figurinhas indisponível.' }
    }
    return capability
  }
  if ((draft.family === 'AUDIO' || draft.family === 'STICKER')
    && draft.file
    && capability.maxBytes !== null
    && draft.file.size > capability.maxBytes) {
    return {
      ...capability,
      enabled: false,
      reason: `O arquivo excede o limite de ${Math.ceil(capability.maxBytes / 1024 / 1024)} MB.`
    }
  }
  if ((draft.family === 'AUDIO' || draft.family === 'STICKER')
    && draft.file
    && capability.mimeTypes.length
    && !capability.mimeTypes.includes(draft.file.type)) {
    return { ...capability, enabled: false, reason: 'O formato do arquivo não é aceito.' }
  }
  return capability
}

const activeCapability = computed(() => capabilityForDraft(currentDraft()))
const available = computed(() => props.canReply
  && props.operational
  && (internalNote.value || props.outboundOperational))
const hasContent = computed(() => draftHasContent(currentDraft()))
const unavailableReason = computed(() => {
  if (!props.canReply) return 'Seu perfil possui acesso somente para leitura.'
  if (!props.operational) {
    return props.unavailableReason || 'A comunicação não está disponível.'
  }
  if (!internalNote.value && !props.outboundOperational) {
    return props.unavailableReason
      || 'A sessão precisa estar conectada para enviar ao WhatsApp.'
  }
  if (!internalNote.value && capabilityError.value) return capabilityError.value
  if (!internalNote.value && !capabilitiesLoading.value && activeCapability.value?.enabled === false) {
    return activeCapability.value.reason
      || 'Este tipo de mensagem não está disponível para esta caixa de entrada.'
  }
  return ''
})
const destination = computed<ComposerDestinationContext>(() => props.destinationContext ?? ({
  conversation: props.conversationId ? `Conversa #${props.conversationId}` : 'Conversa selecionada',
  client: null,
  inbox: props.inboxId ? `Inbox #${props.inboxId}` : 'Caixa de entrada',
  destinationMasked: null
}))
const fileAccept = computed(() => fileSelectionMode.value === 'DOCUMENT'
  ? 'application/pdf,text/plain,application/zip'
  : 'image/jpeg,image/png,image/webp,video/mp4,video/webm')
const hasViewOnce = computed(() => mediaItems.value.some(item => item.viewOnce))
const gifCapability = computed(() => composerCapabilityVariant(
  capabilities.value,
  'VIDEO',
  'gif'
))
const gifProviderCapability = computed(() => composerCapabilityVariant(
  capabilities.value,
  'VIDEO',
  'provider_search'
))
const stickerCapability = computed(() => composerCapability(capabilities.value, 'STICKER'))
const stickerPickerError = computed(() => stickerLibraryError.value ?? stickerLibrary.importError.value)

function launcherActionEnabled(action: ComposerLauncherAction): boolean {
  if (action.id === 'media') {
    return composerCapability(capabilities.value, 'IMAGE').enabled
      || composerCapability(capabilities.value, 'VIDEO').enabled
  }
  if (action.id === 'document') {
    return composerCapability(capabilities.value, 'DOCUMENT').enabled
  }
  if (action.id === 'camera') {
    return composerCapabilityVariant(capabilities.value, 'IMAGE', 'camera').enabled
  }
  return !action.family || composerCapability(capabilities.value, action.family).enabled
}

const launcherGroups = computed(() => composerLauncherGroups
  .map(group => ({
    ...group,
    actions: group.actions.filter(launcherActionEnabled)
  }))
  .filter(group => group.actions.length > 0))
const activeLauncherGroup = computed(() => launcherGroups.value
  .find(group => group.id === launcherGroup.value) ?? null)
const destinationLabel = computed(() => [
  destination.value.conversation,
  destination.value.client,
  destination.value.inbox,
  destination.value.destinationMasked
    ? `Destino ${destination.value.destinationMasked}`
    : null
].filter(Boolean).join(', '))
const cannedItems = computed(() => props.cannedResponses.map(item => ({
  label: `${item.shortcut} · ${item.title}`,
  value: item.id
})))
const autocompleteItems = computed(() => {
  if (!autocompleteOpen.value || !autocompleteToken.value) return []
  return filterCannedResponsesByShortcut(
    props.cannedResponses,
    autocompleteToken.value.query
  )
})
const activeCannedOptionId = computed(() => {
  if (!autocompleteOpen.value) return undefined
  const item = autocompleteItems.value[autocompleteIndex.value]
  return item ? `${listboxId}-option-${item.id}` : undefined
})

function persistDraft(
  context: ComposerDraftContext | null = draftContext.value,
  channel: 'WHATSAPP' | 'INTERNAL_NOTE' = internalNote.value
    ? 'INTERNAL_NOTE'
    : 'WHATSAPP'
) {
  if (suspendPersistence || !context) return
  draftStore.set(context, currentDraftForChannel(channel))
}

function resetLocalDraftState() {
  body.value = ''
  file.value = null
  libraryStickerId.value = null
  fileKind.value = 'TEXT'
  mediaItems.value = []
  ptt.value = false
  sensitiveConfirmed.value = false
  citationId.value = null
  submission.value = createComposerBatchSubmissionKeys()
  structuredFamily.value = null
  structuredEditorError.value = null
  location.value = { latitude: '', longitude: '', name: '', address: '' }
  poll.value = { name: '', options: ['', ''], selectableOptions: 1 }
  eventDraft.value = {
    title: '',
    description: '',
    startsAt: '',
    endsAt: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Sao_Paulo',
    location: ''
  }
  interactive.value = { type: 'BUTTONS', title: '', body: '', actions: ['', ''] }
  selectedContacts.value = []
  contactsDraft.value = []
  selectedCannedId.value = undefined
  lifecycleItems.value = []
  lifecyclePreviousStates.value = {}
  gifSelectionError.value = null
  gifSelectionLoadingId.value = null
  stickerLibraryError.value = null
  stickerSelectionLoadingId.value = null
  voiceRecorder.discard()
  camera.clear()
  cameraOpen.value = false
  stickerEditorOpen.value = false
  structuredEditorOpen.value = false
  contactsEditorOpen.value = false
  drawerOpen.value = false
  attachmentOpen.value = false
  expressionOpen.value = false
  closeAutocomplete()
  if (fileInput.value) fileInput.value.value = ''
  if (stickerInput.value) stickerInput.value.value = ''
}

function restoreSubmission(draft: ComposerDraft) {
  if (draft.channel === 'INTERNAL_NOTE') return
  const stored = draft.submission as Partial<ComposerBatchSubmissionKeys>
  const idempotencyKey = stored.idempotencyKey || createComposerBatchSubmissionKeys().idempotencyKey
  submission.value = {
    idempotencyKey,
    clientBatchId: stored.clientBatchId || `${idempotencyKey}-batch`
  }
}

function restoreDraft(context: ComposerDraftContext) {
  const channel = internalNote.value ? 'INTERNAL_NOTE' : 'WHATSAPP'
  const draft = draftStore.get(context, channel)
  resetLocalDraftState()
  if (!draft) {
    emit('restoreReply', null)
    return
  }

  citationId.value = draft.citation?.replyToMessageId ?? null
  restoreSubmission(draft)
  if (draft.family === 'TEXT') body.value = draft.body
  if (draft.channel === 'WHATSAPP' && draft.family === 'MEDIA_BATCH') {
    mediaItems.value = draft.items.map(item => ({ ...item }))
    sensitiveConfirmed.value = draft.sensitiveConfirmed === true
  }
  if (draft.channel === 'WHATSAPP' && draft.family === 'AUDIO') {
    file.value = draft.file
    fileKind.value = 'AUDIO'
    ptt.value = draft.ptt
  }
  if (draft.channel === 'WHATSAPP' && draft.family === 'STICKER') {
    file.value = draft.file
    libraryStickerId.value = draft.libraryStickerId
    fileKind.value = 'STICKER'
  }
  if (draft.channel === 'WHATSAPP' && draft.family === 'LOCATION') {
    structuredFamily.value = 'LOCATION'
    location.value = {
      latitude: String(draft.location.latitude),
      longitude: String(draft.location.longitude),
      name: draft.location.name ?? '',
      address: draft.location.address ?? ''
    }
  }
  if (draft.channel === 'WHATSAPP' && draft.family === 'CONTACTS') {
    contactsDraft.value = [...draft.contacts]
  }
  if (draft.channel === 'WHATSAPP' && draft.family === 'POLL') {
    structuredFamily.value = 'POLL'
    poll.value = {
      name: draft.poll.name,
      options: [...draft.poll.options],
      selectableOptions: draft.poll.selectableOptions
    }
  }
  if (draft.channel === 'WHATSAPP' && draft.family === 'EVENT') {
    structuredFamily.value = 'EVENT'
    eventDraft.value = {
      title: draft.event.title,
      description: draft.event.description ?? '',
      startsAt: draft.event.startsAt,
      endsAt: draft.event.endsAt,
      timezone: draft.event.timezone,
      location: draft.event.location ?? ''
    }
  }
  if (draft.channel === 'WHATSAPP' && draft.family === 'INTERACTIVE') {
    structuredFamily.value = 'INTERACTIVE'
    interactive.value = {
      type: draft.interactive.type,
      title: draft.interactive.title,
      body: draft.interactive.body,
      actions: draft.interactive.actions.map(action => action.title)
    }
  }
  emit('restoreReply', citationId.value)
}

async function loadCapabilities(
  inboxId: number | null = props.inboxId ?? null
): Promise<boolean> {
  const epoch = ++capabilityRequestEpoch
  if (!inboxId) {
    capabilities.value = null
    capabilityError.value = null
    capabilitiesLoading.value = false
    return false
  }

  capabilitiesLoading.value = true
  capabilityError.value = null
  try {
    const response = await api.communication.catalog.outboundCapabilities({
      inbox_id: inboxId
    })
    if (epoch !== capabilityRequestEpoch || inboxId !== props.inboxId) {
      return false
    }
    capabilities.value = response.data
    return true
  } catch (caught) {
    if (epoch !== capabilityRequestEpoch || inboxId !== props.inboxId) {
      return false
    }
    capabilities.value = null
    capabilityError.value = apiErrorMessage(
      caught,
      'Não foi possível confirmar as permissões de envio.'
    )
    return false
  } finally {
    if (epoch === capabilityRequestEpoch) capabilitiesLoading.value = false
  }
}

watch(draftContext, (next, previous) => {
  if (previous) persistDraft(previous)
  submissionEpoch++
  submitting.value = false
  suspendPersistence = true
  if (next) restoreDraft(next)
  else resetLocalDraftState()
  suspendPersistence = false
  if (next) {
    persistDraft(next)
  }
}, { immediate: true, flush: 'post' })

watch(() => props.inboxId, (inboxId) => {
  void loadCapabilities(inboxId ?? null)
}, { immediate: true, flush: 'post' })

watch(internalNote, (enabled, previous) => {
  const context = draftContext.value
  if (!context) return
  persistDraft(context, previous ? 'INTERNAL_NOTE' : 'WHATSAPP')
  suspendPersistence = true
  restoreDraft(context)
  suspendPersistence = false
  persistDraft(context, enabled ? 'INTERNAL_NOTE' : 'WHATSAPP')
}, { flush: 'sync' })

watch(currentDraft, () => {
  persistDraft()
}, { deep: true })

watch(() => props.replyTo, (replyTo) => {
  if (suspendPersistence) return
  if (replyTo && replyTo.conversation_id !== props.conversationId) return
  citationId.value = replyTo?.id ?? null
})

watch(() => props.outboundOperational, () => {
  if (props.inboxId) void loadCapabilities(props.inboxId)
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

watch(() => voiceRecorder.state.value, (state) => {
  if (state === 'recording') emit('presence', 'RECORDING')
  else if (state !== 'sending') emit('presence', 'PAUSED')
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
  const match = autocompleteToken.value
    ?? findCannedSlashToken(body.value, cursor.value)
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
  if (autocompleteOpen.value && autocompleteItems.value.length
    && shouldHandleCannedAutocompleteKey(event)) {
    if (event.key === 'Escape') {
      event.preventDefault()
      closeAutocomplete()
      return
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      autocompleteIndex.value = (autocompleteIndex.value + 1)
        % autocompleteItems.value.length
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

  if (!shouldSubmitCommunicationComposer(event)) return
  event.preventDefault()
  void submit()
}

function chooseFile(mode: 'MEDIA' | 'DOCUMENT' = 'MEDIA') {
  if (!available.value || internalNote.value || recording.value) return
  fileSelectionMode.value = mode
  void nextTick(() => fileInput.value?.click())
}

function hasExclusiveDraft(): boolean {
  return Boolean(mediaItems.value.length
    || file.value
    || libraryStickerId.value
    || voiceRecorder.recorded.value
    || structuredFamily.value
    || contactsDraft.value.length)
}

function launchComposerAction(action: ComposerLauncherAction) {
  launcherGroup.value = null
  attachmentOpen.value = false
  if (action.id === 'audio') {
    void startRecording()
    return
  }
  if (action.id === 'sticker') {
    if (hasExclusiveDraft()) {
      toast.add({ title: 'Remova o conteúdo atual antes de criar uma figurinha.', color: 'warning' })
      return
    }
    stickerEditorOpen.value = true
    return
  }
  if (action.id === 'contacts') {
    void loadContacts()
    contactsEditorOpen.value = true
    return
  }
  if (action.id === 'camera') {
    if (hasExclusiveDraft()) {
      toast.add({ title: 'Remova o conteúdo atual antes de usar a câmera.', color: 'warning' })
      return
    }
    cameraOpen.value = true
    void openCamera()
    return
  }
  if (action.id === 'media' || action.id === 'document') {
    if (structuredFamily.value || contactsDraft.value.length || file.value || libraryStickerId.value) {
      toast.add({ title: 'Remova o conteúdo estruturado antes de anexar arquivos.', color: 'warning' })
      return
    }
    chooseFile(action.id === 'document' ? 'DOCUMENT' : 'MEDIA')
    return
  }
  if (action.family === 'LOCATION'
    || action.family === 'POLL'
    || action.family === 'EVENT'
    || action.family === 'INTERACTIVE') {
    if (hasExclusiveDraft()) {
      toast.add({ title: 'Remova o conteúdo atual antes de criar outra mensagem.', color: 'warning' })
      return
    }
    structuredFamily.value = action.family
    structuredEditorOpen.value = true
  }
}

function attachCreatedSticker(next: File) {
  if (attachStandaloneFile(next, 'STICKER')) stickerEditorOpen.value = false
}

async function openCamera() {
  const started = await camera.start()
  if (!started) {
    cameraOpen.value = false
    toast.add({
      title: camera.error.value || 'Selecione um arquivo para continuar.',
      color: 'warning'
    })
    chooseFile('MEDIA')
    return
  }
  await nextTick()
  if (cameraVideo.value && camera.stream.value) {
    cameraVideo.value.srcObject = camera.stream.value
  }
}

async function captureCamera() {
  if (!cameraVideo.value) return
  const captured = await camera.capture(cameraVideo.value)
  if (captured) appendMediaFile(captured, 'IMAGE')
  cameraOpen.value = false
  camera.clear()
}

function scheduleContactsLoad(): void {
  if (contactsRequestTimer) clearTimeout(contactsRequestTimer)
  contactsRequestTimer = setTimeout(() => {
    contactsRequestTimer = null
    void loadContacts()
  }, 250)
}

async function loadContacts() {
  const inboxId = props.inboxId
  if (!inboxId) return
  const epoch = ++contactsRequestEpoch
  contactsLoading.value = true
  contactsError.value = null
  try {
    const response = await api.communication.contacts.list({
      inbox_id: inboxId,
      is_active: true,
      q: contactsQuery.value || undefined,
      per_page: 50
    })
    if (epoch !== contactsRequestEpoch || props.inboxId !== inboxId) return
    contactChoices.value = response.data
  } catch (caught) {
    if (epoch !== contactsRequestEpoch || props.inboxId !== inboxId) return
    contactsError.value = apiErrorMessage(
      caught,
      'Não foi possível carregar os contatos autorizados.'
    )
  } finally {
    if (epoch === contactsRequestEpoch) contactsLoading.value = false
  }
}

function syncContactsDraft() {
  contactsDraft.value = selectedContacts.value
    .map(composerContactVCard)
    .filter((item): item is NonNullable<typeof item> => item !== null)
}

function toggleContact(contact: Contact) {
  if (!composerContactVCard(contact)) return
  const index = selectedContacts.value.findIndex(item => item.id === contact.id)
  if (index >= 0) {
    selectedContacts.value.splice(index, 1)
    syncContactsDraft()
    return
  }
  if (selectedContacts.value.length >= contactMaxItems.value) {
    contactsError.value = `Selecione no máximo ${contactMaxItems.value} contatos.`
    return
  }
  selectedContacts.value.push(contact)
  contactsError.value = null
  syncContactsDraft()
}

async function confirmContacts() {
  if (!props.inboxId || !selectedContacts.value.length) return
  contactsLoading.value = true
  contactsError.value = null
  try {
    const contacts = await Promise.all(selectedContacts.value.map(async contact => (
      await api.communication.contacts.get(contact.id, props.inboxId!)
    ).data))
    const normalized = contacts
      .map(composerContactVCard)
      .filter((item): item is NonNullable<typeof item> => item !== null)
    if (normalized.length !== contacts.length) {
      contactsError.value = 'Um contato não possui identidade WhatsApp ativa nesta caixa de entrada. Sua seleção foi preservada.'
      return
    }
    if (normalized.length > contactMaxItems.value) {
      contactsError.value = `Selecione no máximo ${contactMaxItems.value} contatos.`
      return
    }
    selectedContacts.value = contacts
    contactsDraft.value = normalized
    contactsEditorOpen.value = false
  } catch (caught) {
    contactsError.value = apiErrorMessage(caught, 'Não foi possível revalidar os contatos.')
  } finally {
    contactsLoading.value = false
  }
}

function mediaKindForFile(next: File): ComposerMediaKind | null {
  if (fileSelectionMode.value === 'DOCUMENT') return 'DOCUMENT'
  if (next.type.startsWith('image/')) return 'IMAGE'
  if (next.type.startsWith('video/')) return 'VIDEO'
  return null
}

function sessionBinaryBytesWithoutCurrent(): number {
  const context = draftContext.value
  let total = composerSessionBinaryBytes(draftStore.all())
  if (context) {
    total -= composerDraftBinaryBytes(draftStore.get(context, 'WHATSAPP'))
  }
  return Math.max(0, total)
}

function appendMediaItem(item: ComposerMediaItem): boolean {
  const candidate = [...mediaItems.value, item]
  const capability = composerMediaDraftCapability(capabilities.value, candidate)
  if (!capability.enabled) {
    toast.add({ title: capability.reason || 'Este arquivo não pode ser anexado.', color: 'warning' })
    return false
  }
  if (capability.maxBytes !== null && item.file.size > capability.maxBytes) {
    toast.add({
      title: `O arquivo excede o limite de ${Math.ceil(capability.maxBytes / 1024 / 1024)} MB.`,
      color: 'warning'
    })
    return false
  }
  const projected = sessionBinaryBytesWithoutCurrent()
    + candidate.reduce((sum, entry) => sum + entry.file.size, 0)
  if (projected > COMPOSER_SESSION_BINARY_BUDGET_BYTES) {
    toast.add({
      title: 'O rascunho de mídia desta sessão atingiu o limite de memória. Remova arquivos ou envie o lote atual.',
      color: 'warning'
    })
    return false
  }
  mediaItems.value = candidate
  sensitiveConfirmed.value = false
  return true
}

function appendMediaFile(
  next: File,
  kind: ComposerMediaKind,
  variants: Partial<Pick<ComposerMediaItem, 'gif' | 'ptv' | 'viewOnce'>> = {}
): boolean {
  return appendMediaItem({
    clientItemId: crypto.randomUUID(),
    file: next,
    kind,
    caption: '',
    gif: variants.gif === true,
    ptv: variants.ptv === true,
    viewOnce: variants.viewOnce === true
  })
}

function onFile(event: Event) {
  const input = event.target as HTMLInputElement
  const selected = [...(input.files ?? [])]
  for (const next of selected) {
    const kind = mediaKindForFile(next)
    if (!kind) {
      toast.add({ title: 'Selecione uma imagem, um vídeo ou um documento aceito.', color: 'warning' })
      continue
    }
    appendMediaFile(next, kind)
  }
  input.value = ''
}

function attachStandaloneFile(next: File, kind: 'AUDIO' | 'STICKER', asPtt = false): boolean {
  const capability = composerCapability(capabilities.value, kind)
  if (!capability.enabled) {
    toast.add({ title: capability.reason || 'Este tipo de arquivo não está disponível.', color: 'warning' })
    return false
  }
  if (capability.maxBytes !== null && next.size > capability.maxBytes) {
    toast.add({
      title: `O arquivo excede o limite de ${Math.ceil(capability.maxBytes / 1024 / 1024)} MB.`,
      color: 'warning'
    })
    return false
  }
  if (capability.mimeTypes.length && !capability.mimeTypes.includes(next.type)) {
    toast.add({ title: 'O formato do arquivo não é aceito.', color: 'warning' })
    return false
  }
  file.value = next
  libraryStickerId.value = null
  fileKind.value = kind
  ptt.value = asPtt
  return true
}

function onSticker(event: Event) {
  const input = event.target as HTMLInputElement
  const selected = input.files?.[0]
  input.value = ''
  if (!selected) return
  attachStandaloneFile(selected, 'STICKER')
}

function clearStandaloneFile() {
  voiceRecorder.discard()
  file.value = null
  libraryStickerId.value = null
  fileKind.value = 'TEXT'
  ptt.value = false
  if (stickerInput.value) stickerInput.value.value = ''
}

function removeMediaItem(clientItemId: string) {
  mediaItems.value = mediaItems.value.filter(item => item.clientItemId !== clientItemId)
  sensitiveConfirmed.value = false
}

function moveMediaItemUp(index: number) {
  if (index <= 0) return
  const items = [...mediaItems.value]
  const [item] = items.splice(index, 1)
  if (!item) return
  items.splice(index - 1, 0, item)
  mediaItems.value = items
}

function moveMediaItemDown(index: number) {
  if (index >= mediaItems.value.length - 1) return
  moveMediaItemUp(index + 1)
}

function mediaItemValidationMessage(item: ComposerMediaItem, index: number): string | null {
  const draft = currentDraft()
  if (draft.channel !== 'WHATSAPP' || draft.family !== 'MEDIA_BATCH') return null
  return validateComposerDraft(draft, validationLimits(draft))
    .find(error => error.path === `items.${index}`)?.message
    ?? null
}

function confirmStructuredDraft() {
  const draft = currentDraft()
  if (draft.channel !== 'WHATSAPP'
    || (draft.family !== 'LOCATION'
      && draft.family !== 'POLL'
      && draft.family !== 'EVENT'
      && draft.family !== 'INTERACTIVE')) {
    structuredEditorOpen.value = false
    structuredEditorError.value = null
    return
  }
  const errors = validateComposerDraft(draft, validationLimits(draft))
  if (errors.length) {
    structuredEditorError.value = errors[0]?.message ?? 'Revise os campos da mensagem estruturada.'
    return
  }
  structuredEditorError.value = null
  structuredEditorOpen.value = false
}

function restoreAttachmentTriggerFocus() {
  void nextTick(() => {
    const target = attachmentTrigger.value
    const element = target && typeof target === 'object' && '$el' in target
      ? target.$el
      : target
    if (element instanceof HTMLElement) element.focus({ preventScroll: true })
  })
}

function restoreExpressionTriggerFocus() {
  void nextTick(() => {
    const target = expressionTrigger.value
    const element = target && typeof target === 'object' && '$el' in target
      ? target.$el
      : target
    if (element instanceof HTMLElement) element.focus({ preventScroll: true })
  })
}

function updateMediaViewOnce(item: ComposerMediaItem, enabled: boolean) {
  item.viewOnce = enabled
  sensitiveConfirmed.value = false
}

function mediaViewOnceAvailable(item: ComposerMediaItem): boolean {
  return item.kind !== 'DOCUMENT'
    && composerCapabilityVariant(capabilities.value, item.kind, 'view_once').enabled
}

function insertEmoji(emoji: string) {
  const input = composerRoot.value?.querySelector<HTMLTextAreaElement>('textarea')
  const start = input?.selectionStart ?? body.value.length
  const end = input?.selectionEnd ?? start
  body.value = `${body.value.slice(0, start)}${emoji}${body.value.slice(end)}`
  expressionOpen.value = false
  void nextTick(() => {
    input?.focus({ preventScroll: true })
    input?.setSelectionRange(start + emoji.length, start + emoji.length)
  })
  announceComposing()
}

async function searchGifs(query: string) {
  if (!props.inboxId) return []
  return (await api.communication.catalog.searchGifs({
    inbox_id: props.inboxId,
    q: query,
    limit: 20
  })).data
}

async function attachRemoteGif(gif: ComposerGifResult) {
  if (gifSelectionLoadingId.value) return
  gifSelectionError.value = null
  gifSelectionLoadingId.value = gif.id
  try {
    if (!isPrivateGifResult(gif)) throw new Error('O resultado de GIF não é autorizado.')
    const blob = await api.communication.catalog.fetchGifAsset(gif.asset_path)
    const mime = blob.type.split(';')[0]?.toLowerCase()
    if (mime !== 'video/mp4' && mime !== 'video/webm') {
      throw new Error('O GIF remoto retornou um formato inválido.')
    }
    const videoCapability = composerCapability(capabilities.value, 'VIDEO')
    if (videoCapability.maxBytes !== null && blob.size > videoCapability.maxBytes) {
      throw new Error('O GIF remoto excede o limite de tamanho desta caixa de entrada.')
    }
    const extension = mime === 'video/webm' ? 'webm' : 'mp4'
    const safeId = gif.id.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 48) || 'selecionado'
    const next = new File([blob], `gif-${safeId}.${extension}`, { type: mime })
    if (!appendMediaFile(next, 'VIDEO', { gif: true })) {
      throw new Error('O GIF não é compatível com as capabilities atuais.')
    }
    expressionOpen.value = false
  } catch (caught) {
    gifSelectionError.value = caught instanceof Error
      ? caught.message
      : 'Não foi possível preparar o GIF selecionado.'
    toast.add({ title: gifSelectionError.value, color: 'warning' })
  } finally {
    gifSelectionLoadingId.value = null
  }
}

async function attachLibrarySticker(sticker: StickerLibraryItem) {
  if (stickerSelectionLoadingId.value) return
  stickerLibraryError.value = null
  stickerSelectionLoadingId.value = sticker.id
  try {
    if (!sticker.available) {
      throw new Error(sticker.unavailable_reason || 'Esta figurinha não está disponível para envio.')
    }
    const library = composerCapabilityVariant(capabilities.value, 'STICKER', 'library')
    if (!library.enabled) {
      throw new Error(library.reason || 'Biblioteca de figurinhas indisponível.')
    }
    libraryStickerId.value = sticker.id
    file.value = null
    fileKind.value = 'STICKER'
    ptt.value = false
    expressionOpen.value = false
  } catch (caught) {
    stickerLibraryError.value = apiErrorMessage(
      caught,
      'Não foi possível preparar a figurinha selecionada.'
    )
    toast.add({ title: stickerLibraryError.value, color: 'warning' })
  } finally {
    stickerSelectionLoadingId.value = null
  }
}

async function importLibrarySticker(next: File) {
  stickerLibraryError.value = null
  try {
    await stickerLibrary.importSticker(next)
    toast.add({
      title: 'Figurinha importada para a biblioteca.',
      description: 'A importação fica no KontiveHub e não altera os favoritos do dispositivo.',
      color: 'success',
      icon: 'i-lucide-circle-check'
    })
  } catch (caught) {
    stickerLibraryError.value = apiErrorMessage(caught, 'Não foi possível importar a figurinha.')
    toast.add({ title: stickerLibraryError.value, color: 'warning' })
  }
}

async function toggleLibraryStickerFavorite(sticker: StickerLibraryItem) {
  stickerLibraryError.value = null
  try {
    await stickerLibrary.toggleFavorite(sticker)
  } catch (caught) {
    stickerLibraryError.value = apiErrorMessage(
      caught,
      'Não foi possível alterar o favorito do KontiveHub.'
    )
    toast.add({ title: stickerLibraryError.value, color: 'warning' })
  }
}

function loadStickerLibrary(filter: StickerLibraryFilter, append = false) {
  stickerLibraryError.value = null
  void stickerLibrary.load(filter, append)
}

function attachExpressionFile(next: File, kind: 'GIF' | 'STICKER') {
  if (kind === 'STICKER') {
    if (attachStandaloneFile(next, 'STICKER')) expressionOpen.value = false
    return
  }
  if (!['video/mp4', 'video/webm'].includes(next.type)) {
    gifSelectionError.value = 'Selecione um GIF local em vídeo MP4 ou WebM.'
    return
  }
  if (appendMediaFile(next, 'VIDEO', { gif: true })) expressionOpen.value = false
}

function lifecycleForDraft(
  draft: ComposerDraft,
  state: ComposerLifecycleState,
  cause?: string
): ComposerLifecycleItem[] {
  if (draft.channel === 'INTERNAL_NOTE') return []
  if (draft.family === 'MEDIA_BATCH') {
    return draft.items.map(item => ({
      id: item.clientItemId,
      label: item.file.name,
      state,
      ...(cause && { cause })
    }))
  }
  const labels: Record<Exclude<ComposerDraftFamily, 'MEDIA_BATCH'>, string> = {
    TEXT: 'Mensagem de texto',
    AUDIO: draft.family === 'AUDIO' ? draft.file.name : 'Áudio',
    STICKER: draft.family === 'STICKER' ? (draft.file?.name ?? 'Figurinha da biblioteca') : 'Figurinha',
    LOCATION: 'Localização',
    CONTACTS: 'Contatos',
    POLL: 'Enquete',
    EVENT: 'Evento',
    INTERACTIVE: 'Mensagem interativa'
  }
  return [{
    id: draft.submission.idempotencyKey,
    label: labels[draft.family],
    state,
    ...(cause && { cause })
  }]
}

function setLifecycle(
  draft: ComposerDraft,
  state: ComposerLifecycleState,
  cause?: string
) {
  lifecyclePreviousStates.value = Object.fromEntries(
    lifecycleItems.value.map(item => [item.id, item.state])
  )
  lifecycleItems.value = lifecycleForDraft(draft, state, cause)
}

function validationLimits(draft: ComposerDraft) {
  return {
    maxContacts: draft.channel === 'WHATSAPP' && draft.family === 'CONTACTS'
      ? contactMaxItems.value
      : null,
    maxMediaItems: draft.channel === 'WHATSAPP' && draft.family === 'MEDIA_BATCH'
      ? composerCapability(capabilities.value, 'MEDIA_BATCH').maxItems
      : null,
    requireSensitiveConfirmation: true
  }
}

function clearAcknowledgedDraft(
  context: ComposerDraftContext,
  draft: ComposerDraft,
  epoch: number
) {
  draftStore.clear(context, draft.channel)
  if (epoch !== submissionEpoch
    || !sameContext(context, draftContext.value)
    || internalNote.value !== (draft.channel === 'INTERNAL_NOTE')) return

  suspendPersistence = true
  resetLocalDraftState()
  emit('cancelReply')
  emit('restoreReply', null)
  suspendPersistence = false
  persistDraft(context, draft.channel)
}

function lifecycleStateFromMessageStatus(status: Message['status']): ComposerLifecycleState {
  switch (status) {
    case 'SENT': return 'sent'
    case 'DELIVERED': return 'delivered'
    case 'READ': return 'read'
    case 'PLAYED': return 'read'
    case 'FAILED': return 'failed'
    case 'CANCELED': return 'cancelled'
    case 'UNKNOWN': return 'failed'
    case 'ACCEPTED':
    case 'QUEUED':
    default: return 'queued'
  }
}

function acknowledgeSubmission(
  context: ComposerDraftContext,
  draft: ComposerDraft,
  epoch: number,
  ok: boolean,
  messages?: Message[]
) {
  if (ok) {
    if (epoch === submissionEpoch) {
      if (draft.channel === 'WHATSAPP' && draft.family === 'MEDIA_BATCH' && messages && messages.length) {
        lifecyclePreviousStates.value = Object.fromEntries(
          lifecycleItems.value.map(item => [item.id, item.state])
        )
        lifecycleItems.value = lifecycleItems.value.map((item, index) => {
          const matched = messages[index]
          return matched
            ? { ...item, state: lifecycleStateFromMessageStatus(matched.status) }
            : { ...item, state: 'queued' as ComposerLifecycleState }
        })
      } else {
        setLifecycle(draft, 'queued')
      }
    }
    if (draft.channel === 'WHATSAPP' && draft.family === 'AUDIO') {
      voiceRecorder.finishSending()
    }
    clearAcknowledgedDraft(context, draft, epoch)
  } else {
    draftStore.set(context, draft)
    if (epoch === submissionEpoch && sameContext(context, draftContext.value)) {
      setLifecycle(draft, 'failed', 'A API não aceitou o rascunho; nenhuma nova tentativa automática foi feita.')
      if (draft.channel === 'WHATSAPP' && draft.family === 'AUDIO') {
        voiceRecorder.failSending('A mensagem de voz não foi aceita. Revise e tente novamente.')
      }
    }
  }
  if (epoch === submissionEpoch) submitting.value = false
}

async function submit() {
  if (!available.value
    || !hasContent.value
    || props.sending
    || submitting.value
    || insertingCanned.value
    || (voiceSurfaceOpen.value && voiceRecorder.state.value !== 'preview')) return

  const context = draftContext.value
  if (!context) return
  const epoch = ++submissionEpoch
  submitting.value = true
  let draft = currentDraft()
  persistDraft(context)
  setLifecycle(draft, 'validating')

  if (draft.channel === 'WHATSAPP') {
    const capabilitiesReady = await loadCapabilities(context.inboxId)
    if (epoch !== submissionEpoch || !sameContext(context, draftContext.value)) return
    draft = currentDraft()
    const capability = capabilityForDraft(draft)
    if (!capabilitiesReady || !capability?.enabled) {
      const reason = capability?.reason
        || capabilityError.value
        || 'Não foi possível confirmar este envio.'
      persistDraft(context)
      setLifecycle(draft, 'blocked', reason)
      toast.add({ title: reason, color: 'warning' })
      submitting.value = false
      return
    }
  }

  const errors = validateComposerDraft(draft, validationLimits(draft))
  if (errors.length) {
    persistDraft(context)
    setLifecycle(draft, 'blocked', errors[0]!.message)
    toast.add({ title: errors[0]!.message, color: 'warning' })
    submitting.value = false
    return
  }

  if (draft.family === 'TEXT') draft.body = draft.body.trim()
  if (draft.channel === 'WHATSAPP'
    && draft.family === 'AUDIO'
    && voiceRecorder.state.value === 'preview'
    && !voiceRecorder.beginSending()) {
    submitting.value = false
    return
  }
  pausePresence()
  setLifecycle(draft, 'uploading')
  let settled = false
  emit('send', draft, (ok, messages) => {
    if (settled) return
    settled = true
    acknowledgeSubmission(context, draft, epoch, ok, messages)
  })
}

function retryLifecycleItem(itemId: string) {
  const item = lifecycleItems.value.find(candidate => candidate.id === itemId)
  if (item?.state !== 'failed') return
  // O backend é idempotente pelo client_batch_id: reenviar o rascunho não cria
  // duplicatas dos filhos já aceitos. A nova tentativa ressubmete o lote e a
  // resposta reflete o estado por filho novamente.
  void submit()
}

function cancelRecording() {
  voiceRecorder.discard()
}

function finishRecording() {
  voiceRecorder.stop()
}

async function startRecording() {
  if (!available.value || internalNote.value || props.sending || voiceSurfaceOpen.value) return
  if (hasContent.value) {
    toast.add({ title: 'Remova o rascunho atual antes de gravar uma mensagem de voz.', color: 'warning' })
    return
  }
  if (await voiceRecorder.start()) emit('presence', 'RECORDING')
}

function submitRecordedVoice() {
  void submit()
}

function hasSessionBinaryDraft(): boolean {
  for (const slots of draftStore.all().values()) {
    if (composerDraftHasBinary(slots.whatsapp)) return true
  }
  return false
}

function hasPendingBinaryDraft(): boolean {
  return hasSessionBinaryDraft()
    || Boolean(file.value)
    || mediaItems.value.length > 0
    || voiceSurfaceOpen.value
    || Boolean(camera.stream.value)
    || Boolean(camera.capturedFile.value)
    || stickerEditorOpen.value
}

function warnBeforeBinaryDraftLoss(event: BeforeUnloadEvent) {
  if (!hasPendingBinaryDraft()) return
  event.preventDefault()
  event.returnValue = ''
}

onBeforeRouteLeave(() => {
  if (!hasPendingBinaryDraft()) return true
  return window.confirm('Há mídia não enviada neste rascunho. Deseja sair mesmo assim?')
})

onMounted(() => {
  window.addEventListener('beforeunload', warnBeforeBinaryDraftLoss)
})

watch(mediaItems, (items) => {
  mediaPreviewUrls.value = syncComposerMediaPreviewUrls(items, mediaPreviewUrls.value)
}, { deep: true })

watch(drawerOpen, (open, previous) => {
  if (previous && !open) restoreAttachmentTriggerFocus()
})

watch(attachmentOpen, (open, previous) => {
  if (!open) launcherGroup.value = null
  if (previous && !open) restoreAttachmentTriggerFocus()
})

watch(expressionOpen, (open, previous) => {
  if (previous && !open) restoreExpressionTriggerFocus()
})

onBeforeUnmount(() => {
  persistDraft()
  if (pauseTimer) clearTimeout(pauseTimer)
  if (contactsRequestTimer) clearTimeout(contactsRequestTimer)
  contactsRequestEpoch++
  capabilityRequestEpoch++
  submissionEpoch++
  window.removeEventListener('beforeunload', warnBeforeBinaryDraftLoss)
  revokeComposerMediaPreviewUrls(mediaPreviewUrls.value)
  mediaPreviewUrls.value = new Map()
  voiceRecorder.dispose()
  camera.dispose()
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
    class="shrink-0 border-t border-default bg-default/95 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur sm:px-5 sm:py-4"
  >
    <div
      class="mb-2 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted"
      data-testid="communication-composer-context"
      :aria-label="`Contexto autorizado do envio: ${destinationLabel}`"
    >
      <UIcon name="i-lucide-shield-check" class="size-4 shrink-0 text-primary" />
      <span class="truncate font-medium text-highlighted">{{ destination.conversation }}</span>
      <span v-if="destination.client" aria-hidden="true">·</span>
      <span v-if="destination.client" class="truncate">{{ destination.client }}</span>
      <span aria-hidden="true">·</span>
      <span class="truncate">{{ destination.inbox }}</span>
      <span v-if="destination.destinationMasked" aria-hidden="true">·</span>
      <span v-if="destination.destinationMasked" class="truncate">
        Destino {{ destination.destinationMasked }}
      </span>
    </div>

    <p class="sr-only" role="status" aria-live="polite">
      {{ voiceRecorder.state.value === 'recording'
        ? `Gravando áudio há ${formatCommunicationRecordingDuration(voiceRecorder.durationSeconds.value)}.`
        : voiceRecorder.state.value === 'paused'
          ? `Gravação pausada em ${formatCommunicationRecordingDuration(voiceRecorder.durationSeconds.value)}.`
          : voiceRecorder.state.value === 'preview'
            ? 'Mensagem de voz pronta para reprodução ou envio.'
            : voiceRecorder.state.value === 'sending'
              ? 'Enviando mensagem de voz.'
              : voiceRecorder.error.value || unavailableReason || '' }}
    </p>

    <UAlert
      v-if="unavailableReason"
      :title="unavailableReason"
      icon="i-lucide-shield-alert"
      color="warning"
      variant="subtle"
      class="mb-3"
    />

    <div
      class="rounded-xl border bg-elevated/35 p-2.5 shadow-xs transition-colors motion-reduce:transition-none sm:p-3"
      :class="internalNote ? 'border-warning/50' : 'border-default focus-within:border-primary/50'"
    >
      <div
        v-if="replyTo"
        class="mb-2 flex items-start justify-between gap-2 rounded-lg border-s border-primary bg-primary/5 px-3 py-2 text-xs"
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
          class="min-h-11 min-w-11"
          aria-label="Remover citação"
          @click="emit('cancelReply')"
        />
      </div>

      <div class="mb-1.5 flex flex-wrap items-center gap-2">
        <USwitch
          v-model="internalNote"
          label="Nota interna"
          size="sm"
          :disabled="!canReply || sending || submitting"
        />
        <USelectMenu
          v-model="selectedCannedId"
          :items="cannedItems"
          value-key="value"
          placeholder="Resposta pronta"
          size="sm"
          class="ml-auto min-w-40 max-w-52 flex-1"
          data-testid="communication-composer-canned-touch"
          :disabled="!available || sending || submitting || recording"
        />
      </div>

      <div v-if="!voiceSurfaceOpen" class="relative">
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
          :disabled="!available || sending || submitting"
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
            :class="index === autocompleteIndex
              ? 'bg-elevated text-highlighted'
              : 'text-default hover:bg-elevated/70'"
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
        class="mb-2 flex flex-wrap items-center gap-3 rounded-lg bg-elevated px-3 py-2 text-sm"
      >
        <span
          class="size-2 rounded-full bg-error motion-reduce:animate-none"
          :class="voiceRecorder.state.value === 'recording' && 'animate-pulse'"
        />
        <span class="font-medium">
          {{ voiceRecorder.state.value === 'paused' ? 'Pausada' : 'Gravando' }}
          {{ formatCommunicationRecordingDuration(voiceRecorder.durationSeconds.value) }}
        </span>
        <span class="hidden flex-1 items-end gap-0.5 sm:flex" aria-hidden="true">
          <span
            v-for="(sample, index) in voiceRecorder.waveform.value"
            :key="index"
            class="w-0.5 rounded-full bg-primary"
            :style="{ height: `${Math.max(3, sample * 18)}px` }"
          />
        </span>
        <div class="ml-auto flex flex-wrap items-center gap-1">
          <UButton
            icon="i-lucide-trash-2"
            color="error"
            variant="ghost"
            class="min-h-11 min-w-11"
            aria-label="Cancelar gravação"
            @click="cancelRecording"
          />
          <UButton
            :label="voiceRecorder.state.value === 'paused' ? 'Retomar' : 'Pausar'"
            :icon="voiceRecorder.state.value === 'paused' ? 'i-lucide-play' : 'i-lucide-pause'"
            color="neutral"
            variant="soft"
            class="min-h-11"
            @click="voiceRecorder.state.value === 'paused'
              ? voiceRecorder.resume()
              : voiceRecorder.pause()"
          />
          <UButton
            label="Concluir"
            icon="i-lucide-square"
            color="primary"
            variant="soft"
            class="min-h-11"
            @click="finishRecording"
          />
        </div>
      </div>

      <div
        v-else-if="voiceRecorder.state.value === 'preview'"
        data-testid="communication-audio-preview"
        class="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-elevated px-3 py-2 text-sm"
      >
        <UIcon name="i-lucide-audio-lines" class="size-4 text-primary" />
        <span class="font-medium">
          Mensagem de voz · {{ formatCommunicationRecordingDuration(voiceRecorder.durationSeconds.value) }}
        </span>
        <audio
          v-if="voiceRecorder.previewUrl.value"
          :src="voiceRecorder.previewUrl.value"
          controls
          class="h-10 max-w-full"
          aria-label="Reproduzir mensagem de voz"
        />
        <div class="ml-auto flex flex-wrap items-center gap-1">
          <UButton
            label="Gravar novamente"
            color="neutral"
            variant="ghost"
            class="min-h-11"
            @click="cancelRecording(); startRecording()"
          />
          <UButton
            icon="i-lucide-trash-2"
            color="neutral"
            variant="ghost"
            class="min-h-11 min-w-11"
            aria-label="Descartar mensagem de voz"
            @click="cancelRecording"
          />
          <UButton
            label="Enviar"
            icon="i-lucide-send"
            color="primary"
            class="min-h-11"
            :loading="submitting || sending"
            @click="submitRecordedVoice"
          />
        </div>
      </div>

      <div
        v-else-if="voiceRecorder.state.value === 'sending'"
        class="mb-2 flex items-center gap-2 rounded-lg bg-elevated px-3 py-2 text-sm"
        aria-busy="true"
      >
        <UIcon
          name="i-lucide-loader-circle"
          class="size-4 animate-spin text-primary motion-reduce:animate-none"
        />
        Enviando mensagem de voz…
      </div>

      <UAlert
        v-else-if="voiceRecorder.state.value === 'error'"
        :title="voiceRecorder.error.value || 'A gravação de voz falhou.'"
        color="error"
        icon="i-lucide-circle-alert"
        class="mb-2"
      >
        <template #actions>
          <UButton
            v-if="voiceRecorder.recorded.value"
            label="Recuperar gravação"
            color="neutral"
            variant="soft"
            class="min-h-11"
            @click="voiceRecorder.recover"
          />
          <UButton
            label="Descartar"
            color="neutral"
            variant="ghost"
            class="min-h-11"
            @click="cancelRecording"
          />
        </template>
      </UAlert>

      <div
        v-if="mediaItems.length"
        class="mb-2 space-y-2 rounded-lg border border-default bg-elevated p-2 text-xs"
        aria-label="Prévia dos arquivos"
        data-testid="communication-composer-media-preview"
      >
        <article
          v-for="(item, index) in mediaItems"
          :key="item.clientItemId"
          class="grid min-w-0 gap-2 rounded-md bg-default p-2 sm:grid-cols-[auto_auto_minmax(0,1fr)_auto] sm:items-start"
        >
          <span class="font-medium text-muted">{{ index + 1 }}</span>
          <div class="size-14 shrink-0 overflow-hidden rounded-md bg-elevated ring-1 ring-inset ring-default">
            <img
              v-if="composerMediaPreviewKind(item.kind) === 'image' && mediaPreviewUrls.get(item.clientItemId)"
              :src="mediaPreviewUrls.get(item.clientItemId)"
              :alt="`Prévia de ${item.file.name}`"
              class="size-full object-cover"
            >
            <video
              v-else-if="composerMediaPreviewKind(item.kind) === 'video' && mediaPreviewUrls.get(item.clientItemId)"
              :src="mediaPreviewUrls.get(item.clientItemId)"
              class="size-full object-cover"
              muted
              playsinline
              preload="metadata"
              :aria-label="`Prévia de ${item.file.name}`"
            />
            <div
              v-else
              class="flex size-full flex-col items-center justify-center gap-0.5 px-1 text-center text-xs text-muted"
            >
              <UIcon name="i-lucide-file-text" class="size-4" />
              <span class="truncate">{{ item.file.type || 'arquivo' }}</span>
            </div>
          </div>
          <div class="min-w-0 space-y-2">
            <p class="truncate font-medium text-highlighted">
              {{ item.file.name }} · {{ Math.ceil(item.file.size / 1024) }} KB
            </p>
            <UInput
              v-model="item.caption"
              placeholder="Legenda"
              class="w-full"
              :aria-label="`Legenda de ${item.file.name}`"
              :disabled="submitting || sending"
            />
            <div class="flex flex-wrap gap-3">
              <UCheckbox
                v-if="item.kind !== 'DOCUMENT' && (mediaViewOnceAvailable(item) || item.viewOnce)"
                :model-value="item.viewOnce"
                label="Visualização única"
                :disabled="!mediaViewOnceAvailable(item) || submitting || sending"
                @update:model-value="updateMediaViewOnce(item, Boolean($event))"
              />
              <UBadge
                v-if="item.gif"
                label="GIF"
                color="info"
                variant="subtle"
              />
              <UBadge
                v-if="item.ptv"
                label="Vídeo circular"
                color="info"
                variant="subtle"
              />
            </div>
            <p
              v-if="mediaItemValidationMessage(item, index)"
              role="alert"
              class="text-error"
            >
              {{ mediaItemValidationMessage(item, index) }}
            </p>
          </div>
          <div class="flex items-center justify-end gap-1">
            <UButton
              icon="i-lucide-arrow-up"
              color="neutral"
              variant="ghost"
              class="size-11 justify-center rounded-full p-0"
              :disabled="index === 0 || submitting || sending"
              :aria-label="`Mover ${item.file.name} para cima`"
              @click="moveMediaItemUp(index)"
            />
            <UButton
              icon="i-lucide-arrow-down"
              color="neutral"
              variant="ghost"
              class="min-h-11 min-w-11"
              :disabled="index >= mediaItems.length - 1 || submitting || sending"
              :aria-label="`Mover ${item.file.name} para baixo`"
              @click="moveMediaItemDown(index)"
            />
            <UButton
              icon="i-lucide-x"
              color="neutral"
              variant="ghost"
              class="min-h-11 min-w-11"
              :disabled="submitting || sending"
              :aria-label="`Remover ${item.file.name}`"
              @click="removeMediaItem(item.clientItemId)"
            />
          </div>
        </article>

        <UAlert
          v-if="hasViewOnce"
          title="Confirme a privacidade antes do envio"
          :description="`A mídia será aberta uma única vez por ${destination.destinationMasked || destination.conversation} e não poderá ser recuperada pelo composer.`"
          color="warning"
          variant="subtle"
          icon="i-lucide-eye-off"
        >
          <template #actions>
            <UCheckbox
              v-model="sensitiveConfirmed"
              label="Confirmo o destino e a consequência irreversível"
              :disabled="submitting || sending"
            />
          </template>
        </UAlert>
      </div>

      <div
        v-else-if="file || libraryStickerId"
        class="mb-2 flex min-w-0 items-center gap-2 rounded-lg bg-elevated px-3 py-2 text-xs"
      >
        <UIcon
          :name="fileKind === 'STICKER' ? 'i-lucide-sticker' : 'i-lucide-audio-lines'"
          class="size-4 shrink-0 text-primary"
        />
        <div class="min-w-0 flex-1">
          <p class="truncate font-medium text-highlighted">
            {{ libraryStickerId ? `Figurinha ${libraryStickerId}` : file?.name }}
          </p>
          <p class="text-muted">
            {{ fileKind === 'STICKER'
              ? (libraryStickerId ? 'Biblioteca privada' : 'Figurinha WebP')
              : ptt ? 'Mensagem de voz' : `${Math.ceil((file?.size ?? 0) / 1024)} KB` }}
          </p>
        </div>
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          class="min-h-11 min-w-11"
          aria-label="Remover anexo"
          :disabled="submitting || sending"
          @click="clearStandaloneFile"
        />
      </div>

      <div
        v-else-if="contactsDraft.length"
        class="mb-2 flex items-center gap-2 rounded-lg bg-elevated px-3 py-2 text-xs"
      >
        <UIcon name="i-lucide-contact-round" class="size-4 text-primary" />
        <p class="min-w-0 flex-1 truncate font-medium text-highlighted">
          {{ contactsDraft.length === 1
            ? contactsDraft[0]?.displayName
            : `${contactsDraft.length} contatos selecionados` }}
        </p>
        <UButton
          icon="i-lucide-pencil"
          color="neutral"
          variant="ghost"
          class="min-h-11 min-w-11"
          aria-label="Editar contatos"
          @click="contactsEditorOpen = true; loadContacts()"
        />
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          class="min-h-11 min-w-11"
          aria-label="Remover contatos"
          @click="selectedContacts = []; contactsDraft = []"
        />
      </div>

      <div
        v-else-if="structuredFamily"
        class="mb-2 space-y-2 rounded-lg border border-default bg-elevated p-3 text-xs"
        data-testid="communication-composer-structured-preview"
        :aria-label="`Prévia tipada de ${structuredFamily}`"
      >
        <div class="flex items-start gap-2">
          <UIcon name="i-lucide-layout-template" class="mt-0.5 size-4 shrink-0 text-primary" />
          <div class="min-w-0 flex-1 space-y-1">
            <template v-if="structuredFamily === 'LOCATION'">
              <p class="font-medium text-highlighted">
                {{ location.name || 'Localização' }}
              </p>
              <p class="text-muted">
                {{ location.latitude }}, {{ location.longitude }}
              </p>
              <p v-if="location.address" class="text-muted">
                {{ location.address }}
              </p>
            </template>
            <template v-else-if="structuredFamily === 'POLL'">
              <p class="font-medium text-highlighted">
                {{ poll.name || 'Enquete' }}
              </p>
              <ul class="list-disc space-y-0.5 pl-4 text-muted">
                <li
                  v-for="(option, index) in poll.options.filter(Boolean)"
                  :key="`${index}-${option}`"
                >
                  {{ option }}
                </li>
              </ul>
              <p class="text-muted">
                Até {{ poll.selectableOptions }} resposta(s)
              </p>
            </template>
            <template v-else-if="structuredFamily === 'EVENT'">
              <p class="font-medium text-highlighted">
                {{ eventDraft.title || 'Evento' }}
              </p>
              <p class="text-muted">
                {{ eventDraft.startsAt }} → {{ eventDraft.endsAt }} ({{ eventDraft.timezone }})
              </p>
              <p v-if="eventDraft.location" class="text-muted">
                {{ eventDraft.location }}
              </p>
              <p v-if="eventDraft.description" class="text-muted">
                {{ eventDraft.description }}
              </p>
            </template>
            <template v-else>
              <p class="font-medium text-highlighted">
                {{ interactive.title || 'Interativo' }}
              </p>
              <p class="text-muted">
                {{ interactive.body }}
              </p>
              <ul class="list-disc space-y-0.5 pl-4 text-muted">
                <li
                  v-for="(action, index) in interactive.actions.filter(Boolean)"
                  :key="`${index}-${action}`"
                >
                  {{ action }}
                </li>
              </ul>
            </template>
          </div>
          <UButton
            icon="i-lucide-pencil"
            color="neutral"
            variant="ghost"
            class="min-h-11 min-w-11"
            aria-label="Editar mensagem estruturada"
            @click="() => { structuredEditorOpen = true }"
          />
          <UButton
            icon="i-lucide-x"
            color="neutral"
            variant="ghost"
            class="min-h-11 min-w-11"
            aria-label="Remover mensagem estruturada"
            @click="() => { structuredFamily = null }"
          />
        </div>
      </div>

      <ComposerMediaLifecycle
        v-if="lifecycleItems.length"
        class="mb-2"
        :items="lifecycleItems"
        :previous-states="lifecyclePreviousStates"
        @retry="retryLifecycleItem"
      />

      <div class="flex min-w-0 flex-wrap items-center gap-0.5 border-t border-default/70 pt-2">
        <template v-if="!internalNote">
          <input
            ref="fileInput"
            type="file"
            multiple
            class="sr-only"
            :accept="fileAccept"
            @change="onFile"
          >
          <input
            ref="stickerInput"
            type="file"
            class="sr-only"
            accept="image/webp,.webp"
            @change="onSticker"
          >

          <ClientOnly>
            <ComposerAttachmentDrawer
              v-if="mobileLauncher"
              :open="drawerOpen"
              :groups="launcherGroups"
              @update:open="drawerOpen = $event"
              @select="launchComposerAction"
            />
            <UButton
              v-if="mobileLauncher"
              ref="attachmentTrigger"
              icon="i-lucide-plus"
              color="neutral"
              variant="ghost"
              class="min-h-11 min-w-11"
              :disabled="!available || sending || submitting || voiceSurfaceOpen"
              aria-label="Adicionar conteúdo"
              data-testid="communication-composer-attachment-trigger"
              @click="() => { drawerOpen = true }"
            />
          </ClientOnly>

          <UPopover
            v-if="!mobileLauncher"
            v-model:open="attachmentOpen"
            :ui="{ content: 'rounded-xl' }"
            :content="{
              side: 'top',
              align: 'start',
              sideOffset: 8,
              collisionPadding: 12
            }"
          >
            <UButton
              ref="attachmentTrigger"
              icon="i-lucide-plus"
              color="neutral"
              variant="ghost"
              :disabled="!available || sending || submitting || voiceSurfaceOpen"
              aria-label="Adicionar conteúdo"
              class="size-11 justify-center rounded-full p-0"
              data-testid="communication-composer-attachment-trigger"
            />
            <template #content>
              <div
                v-if="capabilitiesLoading"
                class="flex w-64 items-center gap-2 p-3 text-sm text-muted"
                role="status"
                aria-live="polite"
              >
                <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin motion-reduce:animate-none" />
                <span>Carregando opções de envio…</span>
              </div>
              <div
                v-else-if="!launcherGroups.length"
                class="w-64 space-y-3 p-3"
                role="status"
              >
                <div class="flex items-start gap-2 text-sm text-muted">
                  <UIcon name="i-lucide-circle-alert" class="mt-0.5 size-4 shrink-0 text-warning" />
                  <p>
                    {{ capabilityError || 'Nenhuma opção de envio está disponível para esta caixa de entrada.' }}
                  </p>
                </div>
                <UButton
                  label="Tentar novamente"
                  icon="i-lucide-refresh-cw"
                  color="neutral"
                  variant="soft"
                  block
                  class="min-h-11"
                  @click="() => { void loadCapabilities() }"
                />
              </div>
              <div v-else class="w-72 p-2" aria-label="Opções de mensagem">
                <div v-if="activeLauncherGroup" class="flex items-center gap-1 px-1 pb-1">
                  <UButton
                    icon="i-lucide-arrow-left"
                    color="neutral"
                    variant="ghost"
                    class="size-11 justify-center rounded-full p-0"
                    aria-label="Voltar aos grupos"
                    @click="() => { launcherGroup = null }"
                  />
                  <p class="min-w-0 truncate px-1 text-sm font-semibold text-highlighted">
                    {{ activeLauncherGroup.label }}
                  </p>
                </div>
                <template v-if="activeLauncherGroup">
                  <UButton
                    v-for="action in activeLauncherGroup.actions"
                    :key="action.id"
                    :label="action.label"
                    :icon="action.icon"
                    color="neutral"
                    variant="ghost"
                    block
                    class="min-h-11 justify-start rounded-lg px-3"
                    @click="launchComposerAction(action)"
                  />
                </template>
                <template v-else>
                  <UButton
                    v-for="group in launcherGroups"
                    :key="group.id"
                    :label="group.label"
                    :icon="group.icon"
                    trailing-icon="i-lucide-chevron-right"
                    color="neutral"
                    variant="ghost"
                    block
                    class="min-h-11 justify-start rounded-lg px-3"
                    @click="() => { launcherGroup = group.id }"
                  />
                </template>
              </div>
            </template>
          </UPopover>

          <ClientOnly>
            <UDrawer
              v-if="mobileLauncher"
              v-model:open="expressionOpen"
              title="Expressões"
              description="Escolha emoji, GIF ou figurinha para o rascunho."
              :ui="{ content: 'max-h-[90dvh]', body: 'overflow-y-auto p-3' }"
            >
              <UButton
                ref="expressionTrigger"
                icon="i-lucide-smile-plus"
                color="neutral"
                variant="ghost"
                :disabled="!available || sending || submitting || voiceSurfaceOpen"
                aria-label="Adicionar expressão"
                class="size-11 justify-center rounded-full p-0"
              />
              <template #body>
                <ComposerExpressionPicker
                  :open="expressionOpen"
                  :capabilities="{
                    emoji: true,
                    gif: gifCapability.enabled,
                    gifProviderSearch: gifProviderCapability.enabled,
                    sticker: stickerCapability.enabled
                  }"
                  :search-gifs="searchGifs"
                  :selection-error="gifSelectionError || stickerLibraryError"
                  :selection-loading-id="gifSelectionLoadingId || stickerSelectionLoadingId"
                  :sticker-views="stickerLibrary.views"
                  :sticker-preview-urls="stickerLibrary.previewUrls.value"
                  :sticker-mutating-ids="stickerLibrary.mutatingIds.value"
                  :sticker-importing="stickerLibrary.importing.value"
                  :sticker-import-error="stickerPickerError"
                  @close="() => { expressionOpen = false }"
                  @select-emoji="insertEmoji"
                  @select-gif="attachRemoteGif"
                  @select-sticker="attachLibrarySticker"
                  @select-local-gif="(next: File) => attachExpressionFile(next, 'GIF')"
                  @select-local-sticker="(next: File) => attachExpressionFile(next, 'STICKER')"
                  @import-sticker="importLibrarySticker"
                  @load-stickers="loadStickerLibrary"
                  @toggle-sticker-favorite="toggleLibraryStickerFavorite"
                />
              </template>
            </UDrawer>

            <UPopover
              v-else
              v-model:open="expressionOpen"
              :ui="{ content: 'rounded-xl' }"
              :content="{
                side: 'top',
                align: 'start',
                sideOffset: 8,
                collisionPadding: 12
              }"
            >
              <UButton
                ref="expressionTrigger"
                icon="i-lucide-smile-plus"
                color="neutral"
                variant="ghost"
                :disabled="!available || sending || submitting || voiceSurfaceOpen"
                aria-label="Adicionar expressão"
                class="size-11 justify-center rounded-full p-0"
              />
              <template #content>
                <ComposerExpressionPicker
                  :open="expressionOpen"
                  :capabilities="{
                    emoji: true,
                    gif: gifCapability.enabled,
                    gifProviderSearch: gifProviderCapability.enabled,
                    sticker: stickerCapability.enabled
                  }"
                  :search-gifs="searchGifs"
                  :selection-error="gifSelectionError || stickerLibraryError"
                  :selection-loading-id="gifSelectionLoadingId || stickerSelectionLoadingId"
                  :sticker-views="stickerLibrary.views"
                  :sticker-preview-urls="stickerLibrary.previewUrls.value"
                  :sticker-mutating-ids="stickerLibrary.mutatingIds.value"
                  :sticker-importing="stickerLibrary.importing.value"
                  :sticker-import-error="stickerPickerError"
                  @close="() => { expressionOpen = false }"
                  @select-emoji="insertEmoji"
                  @select-gif="attachRemoteGif"
                  @select-sticker="attachLibrarySticker"
                  @select-local-gif="(next: File) => attachExpressionFile(next, 'GIF')"
                  @select-local-sticker="(next: File) => attachExpressionFile(next, 'STICKER')"
                  @import-sticker="importLibrarySticker"
                  @load-stickers="loadStickerLibrary"
                  @toggle-sticker-favorite="toggleLibraryStickerFavorite"
                />
              </template>
            </UPopover>
          </ClientOnly>
        </template>

        <span class="ml-1 hidden text-xs text-dimmed sm:inline">
          Enter envia · Shift+Enter quebra linha · /atalho
        </span>
        <UButton
          class="ml-auto min-h-11"
          :label="internalNote ? 'Adicionar nota' : 'Enviar'"
          :icon="internalNote ? 'i-lucide-sticky-note' : 'i-lucide-send'"
          :color="internalNote ? 'warning' : 'primary'"
          :loading="sending || submitting"
          :disabled="!available
            || !hasContent
            || voiceSurfaceOpen
            || Boolean(unavailableReason)
            || capabilitiesLoading && !internalNote"
          @click="submit"
        />
      </div>
    </div>

    <UModal
      v-model:open="stickerEditorOpen"
      title="Criar figurinha"
      description="A figurinha permanece somente nesta sessão até o envio."
    >
      <template #body>
        <ComposerStickerEditor
          :max-bytes="stickerCapability.maxBytes ?? MAX_MEDIA_BYTES"
          :max-dimension="512"
          @confirm="attachCreatedSticker"
          @cancel="stickerEditorOpen = false"
        />
      </template>
    </UModal>

    <UModal
      v-model:open="cameraOpen"
      title="Câmera"
      description="Capture uma imagem para adicionar ao rascunho."
      @update:open="open => { if (!open) camera.clear() }"
    >
      <template #body>
        <video
          ref="cameraVideo"
          autoplay
          playsinline
          muted
          class="w-full rounded-lg bg-elevated"
          aria-label="Prévia da câmera"
        />
        <p v-if="camera.error.value" class="mt-2 text-sm text-warning">
          {{ camera.error.value }}
        </p>
      </template>
      <template #footer>
        <UButton
          label="Cancelar"
          color="neutral"
          variant="ghost"
          class="min-h-11"
          @click="() => { cameraOpen = false; camera.clear() }"
        />
        <UButton
          label="Capturar"
          icon="i-lucide-camera"
          color="primary"
          class="min-h-11"
          :disabled="camera.state.value !== 'streaming'"
          @click="captureCamera"
        />
      </template>
    </UModal>

    <UModal
      v-model:open="contactsEditorOpen"
      title="Selecionar contatos"
      :description="`Apenas contatos autorizados desta caixa. Limite atual: ${contactMaxItems}.`"
    >
      <template #body>
        <UInput
          v-model="contactsQuery"
          aria-label="Buscar contato"
          placeholder="Buscar contato"
          icon="i-lucide-search"
          class="mb-3"
          @update:model-value="scheduleContactsLoad"
        />
        <p v-if="contactsError" role="alert" class="mb-2 text-sm text-error">
          {{ contactsError }}
        </p>
        <p v-else-if="contactsLoading" class="text-sm text-muted">
          Carregando contatos…
        </p>
        <p v-else-if="!contactChoices.length" class="text-sm text-muted">
          Nenhum contato autorizado encontrado.
        </p>
        <div
          v-else
          class="space-y-1"
          role="listbox"
          aria-label="Contatos autorizados"
          aria-multiselectable="true"
        >
          <UButton
            v-for="contact in contactChoices"
            :key="contact.id"
            :label="contact.display_name || contact.name || `Contato #${contact.id}`"
            :disabled="!composerContactVCard(contact)"
            :color="selectedContacts.some(item => item.id === contact.id)
              ? 'primary'
              : 'neutral'"
            :variant="selectedContacts.some(item => item.id === contact.id)
              ? 'soft'
              : 'ghost'"
            :aria-selected="selectedContacts.some(item => item.id === contact.id)"
            role="option"
            block
            class="min-h-11 justify-start"
            @click="toggleContact(contact)"
          />
        </div>
      </template>
      <template #footer>
        <UButton
          label="Cancelar"
          color="neutral"
          variant="ghost"
          class="min-h-11"
          @click="() => { contactsEditorOpen = false }"
        />
        <UButton
          label="Confirmar contatos"
          color="primary"
          class="min-h-11"
          :loading="contactsLoading"
          :disabled="!selectedContacts.length || selectedContacts.length > contactMaxItems"
          @click="confirmContacts"
        />
      </template>
    </UModal>

    <UModal
      v-model:open="structuredEditorOpen"
      title="Criar mensagem estruturada"
      :description="`Destino: ${destination.destinationMasked || destination.conversation}`"
    >
      <template #body>
        <div v-if="structuredFamily === 'LOCATION'" class="grid gap-3 sm:grid-cols-2">
          <UFormField label="Latitude" required>
            <UInput v-model="location.latitude" inputmode="decimal" />
          </UFormField>
          <UFormField label="Longitude" required>
            <UInput v-model="location.longitude" inputmode="decimal" />
          </UFormField>
          <UFormField label="Nome" class="sm:col-span-2">
            <UInput v-model="location.name" />
          </UFormField>
          <UFormField label="Endereço" class="sm:col-span-2">
            <UInput v-model="location.address" />
          </UFormField>
        </div>
        <div v-else-if="structuredFamily === 'POLL'" class="grid gap-3">
          <UFormField label="Pergunta" required>
            <UInput v-model="poll.name" />
          </UFormField>
          <UFormField
            v-for="(_, index) in poll.options"
            :key="index"
            :label="`Opção ${index + 1}`"
            required
          >
            <UInput v-model="poll.options[index]" />
          </UFormField>
          <UButton
            label="Adicionar opção"
            color="neutral"
            variant="soft"
            class="min-h-11"
            @click="() => { poll.options.push('') }"
          />
          <UFormField label="Respostas permitidas">
            <UInput
              v-model.number="poll.selectableOptions"
              type="number"
              min="1"
              :max="poll.options.length"
            />
          </UFormField>
        </div>
        <div v-else-if="structuredFamily === 'EVENT'" class="grid gap-3">
          <UFormField label="Título" required>
            <UInput v-model="eventDraft.title" />
          </UFormField>
          <UFormField label="Início" required>
            <UInput v-model="eventDraft.startsAt" type="datetime-local" />
          </UFormField>
          <UFormField label="Fim" required>
            <UInput v-model="eventDraft.endsAt" type="datetime-local" />
          </UFormField>
          <UFormField label="Fuso horário" required>
            <UInput v-model="eventDraft.timezone" />
          </UFormField>
          <UFormField label="Local">
            <UInput v-model="eventDraft.location" />
          </UFormField>
          <UFormField label="Descrição">
            <UTextarea v-model="eventDraft.description" :rows="2" />
          </UFormField>
        </div>
        <div v-else-if="structuredFamily === 'INTERACTIVE'" class="grid gap-3">
          <UFormField label="Título" required>
            <UInput v-model="interactive.title" />
          </UFormField>
          <UFormField label="Mensagem" required>
            <UTextarea v-model="interactive.body" :rows="3" />
          </UFormField>
          <UFormField
            v-for="(_, index) in interactive.actions"
            :key="index"
            :label="`Ação ${index + 1}`"
            required
          >
            <UInput v-model="interactive.actions[index]" />
          </UFormField>
        </div>
        <p
          v-if="structuredEditorError"
          role="alert"
          class="mt-3 text-sm text-error"
        >
          {{ structuredEditorError }}
        </p>
      </template>
      <template #footer>
        <UButton
          label="Cancelar"
          color="neutral"
          variant="ghost"
          class="min-h-11"
          @click="structuredFamily = null; structuredEditorError = null; structuredEditorOpen = false"
        />
        <UButton
          label="Usar no composer"
          color="primary"
          class="min-h-11"
          @click="confirmStructuredDraft"
        />
      </template>
    </UModal>
  </div>
</template>

<style scoped>
@media (max-width: 639px) {
  :deep(input),
  :deep(textarea),
  :deep(select) {
    font-size: 1rem;
  }
}
</style>
