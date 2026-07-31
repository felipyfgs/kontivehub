<script setup lang="ts">
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import type { DropdownMenuItem } from '@nuxt/ui'
import type {
  CommunicationComposerPayload,
  CommunicationContact,
  CommunicationConversation,
  CommunicationConversationActionPayload,
  CommunicationConversationQuickView,
  CommunicationConversationStatus,
  CommunicationMessage
} from '~/types/communication'
import { apiErrorMessage } from '~/utils/api-error'
import {
  COMMUNICATION_REALTIME_META,
  communicationSnoozeTomorrowMorning,
  communicationSnoozeUntil
} from '~/utils/communication'
import {
  COMMUNICATION_INDEX_PATH,
  communicationContactConversationsPath,
  communicationConversationMessagePath,
  communicationConversationPath,
  parseCommunicationContactId,
  parseCommunicationConversationId,
  parseCommunicationMessageId
} from '~/utils/communication-routes'
import { normalizeCommunicationConversationSortBy } from '~/utils/communication-conversation-sort'
import { communicationQuickViewState } from '~/utils/communication-conversation-quick-views'

type BulkActionMenu = {
  key: 'more' | 'read' | 'status'
  align: 'end' | 'start'
  label: string
  icon: string
  items: DropdownMenuItem[] | DropdownMenuItem[][]
}

const workspace = useCommunicationWorkspace()
const api = useApi()
const toast = useToast()
const download = useAuthenticatedDownload()
const route = useRoute()
const router = useRouter()
const breakpoints = useBreakpoints(breakpointsTailwind)
const isMobile = breakpoints.smaller('lg')
const usesContextSlideover = breakpoints.smaller('2xl')
const conversationListRef = ref<{
  focusConversation: (conversationId: number) => Promise<boolean>
  focusList: () => Promise<boolean>
} | null>(null)
const administrationOpen = ref(false)
const contextOpen = ref(false)
const purgeOpen = ref(false)
const purgeContactId = ref<number | null>(null)
const purging = ref(false)
const routeApplyEpoch = ref(0)
const newConversationOpen = ref(false)
const newConversationContact = ref<CommunicationContact | null>(null)
const newConversationContacts = ref<CommunicationContact[]>([])
const newConversationLoading = ref(false)
const conversationActionPending = ref(false)
const contactFilterName = ref<string | null>(null)
const pendingConversationFocusId = ref<number | null>(null)
let mobileFocusRestoreTimer: ReturnType<typeof setTimeout> | null = null
let contactFilterRequestSequence = 0

const routeConversationId = computed(() => parseCommunicationConversationId(route.params.id))
const routeMessageId = computed(() => parseCommunicationMessageId(route.params.messageId))
const routeContactId = computed(() => parseCommunicationContactId(route.params.contactId))

const mobileConversationOpen = computed({
  get: () => isMobile.value && workspace.selectedConversation.value !== null,
  set: (value: boolean) => {
    if (!value && workspace.selectedConversationId.value !== null) {
      void clearConversationSelection()
    }
  }
})

const inboxItems = computed(() => [
  { label: 'Todas as inboxes', value: 0 },
  ...workspace.inboxes.value.map(inbox => ({ label: inbox.name, value: inbox.id }))
])
const statusSelection = computed({
  get: (): CommunicationConversationStatus | 'ALL' => workspace.statusFilter.value || 'ALL',
  set: (value: CommunicationConversationStatus | 'ALL') => {
    workspace.statusFilter.value = value === 'ALL' ? null : value
  }
})
const inboxSelection = computed({
  get: () => workspace.inboxFilter.value || 0,
  set: (value: number) => workspace.inboxFilter.value = value || null
})
const assigneeItems = computed(() => [
  { label: 'Qualquer responsável', value: 0 },
  ...workspace.tenantMembers.value.map(member => ({
    label: member.name || member.email || `Membro #${member.id}`,
    value: member.id
  }))
])
const departmentItems = computed(() => [
  { label: 'Qualquer fila', value: 0 },
  ...workspace.departments.value.map(department => ({
    label: department.name,
    value: department.id
  }))
])
const labelItems = computed(() =>
  workspace.labels.value.map(label => ({
    label: label.name,
    value: label.id
  }))
)
const assigneeSelection = computed({
  get: () => workspace.assigneeFilter.value || 0,
  set: (value: number) => {
    if (value) workspace.unassignedOnly.value = false
    workspace.assigneeFilter.value = value || null
  }
})
const unassignedOnlySelection = computed({
  get: () => workspace.unassignedOnly.value,
  set: (value: boolean) => {
    workspace.unassignedOnly.value = value
    if (value) workspace.assigneeFilter.value = null
  }
})
const departmentSelection = computed({
  get: () => workspace.departmentFilter.value || 0,
  set: (value: number) => workspace.departmentFilter.value = value || null
})
const labelSelection = computed({
  get: () => workspace.labelIdsFilter.value,
  set: (value: number[] | undefined) => {
    workspace.labelIdsFilter.value = Array.isArray(value) ? value : []
  }
})
const sortSelection = computed({
  get: () => workspace.sortBy.value,
  set: (value: unknown) => {
    workspace.sortBy.value = normalizeCommunicationConversationSortBy(value)
  }
})

function applyQuickView(view: CommunicationConversationQuickView): void {
  const next = communicationQuickViewState(view)
  workspace.clearOperationalSelection()
  workspace.statusFilter.value = next.status
  workspace.unreadOnly.value = next.unreadOnly
  workspace.unassignedOnly.value = next.unassignedOnly
  if (next.unassignedOnly) workspace.assigneeFilter.value = null
}

async function loadContactFilterName(): Promise<void> {
  const id = workspace.contactIdFilter.value
  const requestSequence = ++contactFilterRequestSequence
  contactFilterName.value = null
  if (!id) return
  try {
    const response = await api.communication.contacts.get(id)
    if (
      requestSequence !== contactFilterRequestSequence
      || workspace.contactIdFilter.value !== id
    ) return
    contactFilterName.value = response.data.name?.trim() || null
  } catch {
    // O filtro permanece útil mesmo sem poder resolver um nome apresentável.
  }
}

function clearContactFilter(): void {
  ++contactFilterRequestSequence
  workspace.contactIdFilter.value = null
  contactFilterName.value = null
  void router.replace(COMMUNICATION_INDEX_PATH)
}

function openAdministration() {
  administrationOpen.value = true
}

async function openNewConversation() {
  const contactId = workspace.selectedConversation.value?.contact?.id
  if (!workspace.canReply.value || newConversationLoading.value) return
  newConversationLoading.value = true
  try {
    if (contactId) {
      newConversationContact.value = (await api.communication.contacts.get(contactId)).data
      newConversationContacts.value = []
    } else {
      newConversationContact.value = null
      newConversationContacts.value = []
    }
    newConversationOpen.value = true
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Não foi possível carregar o contato para iniciar a conversa.'),
      color: 'error'
    })
  } finally {
    newConversationLoading.value = false
  }
}

async function onConversationCreated(conversationId: number) {
  newConversationOpen.value = false
  await router.push(communicationConversationPath(conversationId))
  await workspace.reloadConversations()
}

function closePurge() {
  purgeOpen.value = false
}

async function syncRouteToSelection(id: number | null): Promise<void> {
  const contactId = routeContactId.value
  const target = contactId
    ? communicationContactConversationsPath(contactId, id ?? undefined)
    : id === null
      ? COMMUNICATION_INDEX_PATH
      : communicationConversationPath(id)
  if (route.path === target) return
  await router.push(target)
}

async function openConversation(id: number): Promise<void> {
  contextOpen.value = false
  pendingConversationFocusId.value = null
  if (mobileFocusRestoreTimer !== null) {
    clearTimeout(mobileFocusRestoreTimer)
    mobileFocusRestoreTimer = null
  }
  const epoch = ++routeApplyEpoch.value
  const ok = await workspace.selectConversation(id)
  if (epoch !== routeApplyEpoch.value) return
  if (!ok) {
    await syncRouteToSelection(null)
    return
  }
  await syncRouteToSelection(id)
}

async function clearConversationSelection(): Promise<void> {
  const conversationId = workspace.selectedConversationId.value
  if (conversationId === null) return
  pendingConversationFocusId.value = conversationId
  contextOpen.value = false
  const epoch = ++routeApplyEpoch.value
  await workspace.selectConversation(null)
  if (epoch !== routeApplyEpoch.value) return
  await syncRouteToSelection(null)
  if (!isMobile.value) {
    await restoreConversationFocus()
    return
  }
  scheduleMobileConversationFocusRestore(conversationId, 750)
}

async function closeMobileConversation(): Promise<void> {
  const conversationId = workspace.selectedConversationId.value
  await clearConversationSelection()
  if (conversationId !== null) {
    scheduleMobileConversationFocusRestore(conversationId, 750)
  }
}

function scheduleMobileConversationFocusRestore(conversationId: number, delay: number): void {
  if (mobileFocusRestoreTimer !== null) clearTimeout(mobileFocusRestoreTimer)
  mobileFocusRestoreTimer = setTimeout(() => {
    mobileFocusRestoreTimer = null
    void focusConversationAfterOverlay(conversationId)
  }, delay)
}

function restoreConversationFocusAfterLeave(): void {
  const conversationId = pendingConversationFocusId.value
  if (conversationId === null) return
  // DialogContent ainda conclui a desmontagem do focus scope depois de emitir
  // after:leave; o próximo macrotask evita que essa limpeza sobrescreva o foco.
  scheduleMobileConversationFocusRestore(conversationId, 0)
}

async function restoreConversationFocus(): Promise<void> {
  const conversationId = pendingConversationFocusId.value
  if (conversationId === null) return
  await focusConversationAfterOverlay(conversationId)
}

async function focusConversationAfterOverlay(conversationId: number): Promise<void> {
  if (pendingConversationFocusId.value !== conversationId) return
  pendingConversationFocusId.value = null
  await nextTick()
  await new Promise<void>((resolve) => {
    requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
  })
  let restored = await conversationListRef.value?.focusConversation(conversationId)
  if (!restored) {
    const button = document.getElementById(`communication-conversation-${conversationId}`)
    if (button instanceof HTMLButtonElement) {
      button.focus({ preventScroll: true })
      restored = document.activeElement === button
    }
  }
}

async function selectConversation(conversation: CommunicationConversation) {
  await openConversation(conversation.id)
}

function prefetchConversation(conversationId: number): void {
  void workspace.prefetchConversation(conversationId)
}

function onToggleSelect(conversationId: number, selected: boolean): void {
  workspace.setConversationSelected(conversationId, selected)
}

const bulkSelectionToggleLabel = computed(() => {
  if (workspace.allLoadedSelected.value) return 'Desmarcar todas as conversas carregadas'
  const count = workspace.conversations.value.length
  return count === 1
    ? 'Selecionar 1 conversa carregada'
    : `Selecionar ${count} conversas carregadas`
})

const conversationTotalLabel = computed(() => {
  const count = workspace.conversationsTotal.value
  return count === 1
    ? '1 conversa no filtro atual'
    : `${count} conversas no filtro atual`
})

const realtimeStatusClass = computed(() => {
  const color = COMMUNICATION_REALTIME_META[workspace.realtimeState.value].color
  if (color === 'success') return 'text-success'
  if (color === 'warning') return 'text-warning'
  return 'text-muted'
})

const navbarMenuItems = computed<DropdownMenuItem[][]>(() => [[
  {
    label: 'Sincronizar conversas',
    icon: 'i-lucide-refresh-cw',
    disabled: workspace.syncing.value,
    onSelect: () => void workspace.synchronize()
  },
  ...(workspace.canManage.value
    ? [{
        label: 'Administrar comunicação',
        icon: 'i-lucide-settings-2',
        onSelect: openAdministration
      }]
    : [])
]])

const bulkSelectionAnnouncement = computed(() => {
  const count = workspace.selectedConversationCount.value
  if (count === 0) return ''
  return count === 1 ? '1 conversa selecionada' : `${count} conversas selecionadas`
})

async function clearBulkSelection(): Promise<void> {
  const selectedIds = [...workspace.selectedConversationIds.value]
  const focusId = selectedIds.at(-1) ?? null
  workspace.clearOperationalSelection()
  await nextTick()

  if (focusId !== null) {
    const focused = await conversationListRef.value?.focusConversation(focusId)
    if (focused) return
  }
  await conversationListRef.value?.focusList()
}

function onBulkSelectAllChange(value: boolean | 'indeterminate'): void {
  if (value === true) {
    workspace.toggleSelectAllLoaded(true)
    return
  }
  void clearBulkSelection()
}

function unsupportedConversationAction(_action: never): never {
  throw new Error('Ação de conversa não suportada.')
}

async function onConversationAction(
  payload: CommunicationConversationActionPayload
): Promise<void> {
  if (conversationActionPending.value) return
  const { conversation, action } = payload
  conversationActionPending.value = true
  try {
    let updated = false
    if (action.type === 'MARK_READ') {
      updated = await workspace.markConversationRead(conversation.id)
    } else if (action.type === 'MARK_UNREAD') {
      updated = await workspace.markConversationUnread(conversation.id)
    } else if (action.type === 'SET_STATUS') {
      updated = await workspace.updateConversation({
        status: action.status,
        snoozed_until: action.status === 'SNOOZED'
          ? action.snoozed_until ?? null
          : null
      }, conversation)
    } else if (action.type === 'SET_ASSIGNEE') {
      updated = await workspace.updateConversation({
        assignee_membership_id: action.assignee_membership_id
      }, conversation)
    } else if (action.type === 'SET_DEPARTMENT') {
      updated = await workspace.updateConversation({
        work_department_id: action.work_department_id
      }, conversation)
    } else if (action.type === 'SET_LABEL') {
      updated = await workspace.setConversationLabel(
        conversation,
        action.label_id,
        action.assigned
      )
    } else {
      unsupportedConversationAction(action)
    }
    if (updated) await workspace.reloadConversations()
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao aplicar a ação na conversa.'),
      color: 'error'
    })
  } finally {
    conversationActionPending.value = false
  }
}

const bulkActionMenus = computed<BulkActionMenu[]>(() => {
  const menus: BulkActionMenu[] = []
  const selected = workspace.conversations.value.filter(conversation =>
    workspace.selectedConversationIds.value.has(conversation.id))
  if (!selected.length) return menus

  if (workspace.canView.value) {
    const readItems: DropdownMenuItem[] = []
    if (selected.some(conversation => (conversation.unread_count ?? 0) > 0)) {
      readItems.push({
        label: 'Marcar como lidas',
        icon: 'i-lucide-mail-check',
        onSelect: () => void workspace.submitBulkOperation('MARK_READ')
      })
    }
    if (selected.some(conversation => (conversation.unread_count ?? 0) === 0)) {
      readItems.push({
        label: 'Marcar como não lidas',
        icon: 'i-lucide-mail',
        onSelect: () => void workspace.submitBulkOperation('MARK_UNREAD')
      })
    }
    if (readItems.length) {
      menus.push({
        key: 'read',
        align: 'start',
        label: 'Alterar leitura',
        icon: 'i-lucide-mail-check',
        items: readItems
      })
    }
  }

  if (workspace.canReply.value) {
    const statusItems: DropdownMenuItem[] = []
    const addStatus = (
      status: CommunicationConversationStatus,
      label: string,
      icon: string
    ) => {
      if (selected.every(conversation => conversation.status === status)) return
      statusItems.push({
        label,
        icon,
        onSelect: () => void workspace.submitBulkOperation('SET_STATUS', { status })
      })
    }
    addStatus('RESOLVED', 'Resolver', 'i-lucide-circle-check')
    addStatus('PENDING', 'Pendente', 'i-lucide-clock-3')
    addStatus('OPEN', 'Reabrir', 'i-lucide-rotate-ccw')
    statusItems.push({
      label: 'Adiar 1 hora',
      icon: 'i-lucide-alarm-clock',
      onSelect: () => void workspace.submitBulkOperation('SET_STATUS', {
        status: 'SNOOZED',
        snoozed_until: communicationSnoozeUntil(1)
      })
    }, {
      label: 'Adiar até amanhã 9h',
      icon: 'i-lucide-sunrise',
      onSelect: () => void workspace.submitBulkOperation('SET_STATUS', {
        status: 'SNOOZED',
        snoozed_until: communicationSnoozeTomorrowMorning()
      })
    })
    menus.push({
      key: 'status',
      align: 'start',
      label: 'Alterar status',
      icon: 'i-lucide-circle-fading-arrow-up',
      items: statusItems
    })

    const moreItems: DropdownMenuItem[] = []
    const assigneeItems: DropdownMenuItem[] = [{
      label: 'Sem responsável',
      icon: 'i-lucide-user-round-x',
      disabled: selected.every(conversation => conversation.assignee_membership_id == null),
      onSelect: () => void workspace.submitBulkOperation('SET_ASSIGNEE', {
        assignee_membership_id: null
      })
    }, ...workspace.tenantMembers.value.map(member => ({
      label: member.name || member.email || `Membro #${member.id}`,
      icon: 'i-lucide-user-round-check',
      disabled: selected.every(conversation =>
        conversation.assignee_membership_id === member.id),
      onSelect: () => void workspace.submitBulkOperation('SET_ASSIGNEE', {
        assignee_membership_id: member.id
      })
    }))].filter(item => !item.disabled)
    if (assigneeItems.length) {
      moreItems.push({
        label: 'Responsável',
        icon: 'i-lucide-user-round-check',
        children: assigneeItems,
        content: { collisionPadding: 8 }
      })
    }

    const departmentItems: DropdownMenuItem[] = [{
      label: 'Sem fila',
      icon: 'i-lucide-list-x',
      disabled: selected.every(conversation => conversation.work_department_id == null),
      onSelect: () => void workspace.submitBulkOperation('SET_DEPARTMENT', {
        work_department_id: null
      })
    }, ...workspace.departments.value.map(department => ({
      label: department.name,
      icon: 'i-lucide-list-tree',
      disabled: selected.every(conversation =>
        conversation.work_department_id === department.id),
      onSelect: () => void workspace.submitBulkOperation('SET_DEPARTMENT', {
        work_department_id: department.id
      })
    }))].filter(item => !item.disabled)
    if (departmentItems.length) {
      moreItems.push({
        label: 'Fila',
        icon: 'i-lucide-list-tree',
        children: departmentItems,
        content: { collisionPadding: 8 }
      })
    }

    if (workspace.labels.value.length) {
      moreItems.push({
        label: 'Marcadores',
        icon: 'i-lucide-tags',
        children: workspace.labels.value.map((label) => {
          const assignedToAll = selected.every(conversation =>
            conversation.labels?.some(item => item.id === label.id))
          return {
            label: label.name,
            type: 'checkbox' as const,
            checked: assignedToAll,
            onSelect: (event: Event) => {
              event.preventDefault()
              void workspace.submitBulkOperation(
                assignedToAll ? 'REMOVE_LABELS' : 'ADD_LABELS',
                { label_ids: [label.id] }
              )
            }
          }
        }),
        content: { collisionPadding: 8 }
      })
    }

    if (moreItems.length) {
      menus.push({
        key: 'more',
        align: 'end',
        label: 'Mais ações',
        icon: 'i-lucide-ellipsis-vertical',
        items: moreItems
      })
    }
  }

  return menus
})

async function applyRouteConversation(id: number | null): Promise<void> {
  if (!workspace.canView.value || !workspace.initialized.value) return
  const epoch = ++routeApplyEpoch.value
  if (id === null) {
    if (workspace.selectedConversationId.value !== null) {
      await workspace.selectConversation(null)
    }
    return
  }
  const anchorMessageId = routeMessageId.value
  if (workspace.selectedConversation.value?.id === id && !anchorMessageId) return
  let ok = anchorMessageId
    ? await workspace.selectConversationAtMessage(id, anchorMessageId)
    : await workspace.selectConversation(id)
  if (epoch !== routeApplyEpoch.value) return
  if (!ok && anchorMessageId) {
    ok = await workspace.selectConversation(id)
    if (epoch !== routeApplyEpoch.value) return
    if (ok) {
      toast.add({
        title: 'Mensagem indisponível',
        description: 'A conversa foi mantida, mas a mensagem de origem não pode mais ser exibida.',
        color: 'warning'
      })
      await router.replace({
        path: communicationConversationPath(id)
      })
      return
    }
  }
  if (!ok && workspace.selectedConversationId.value !== id) {
    await syncRouteToSelection(null)
  }
}

async function jumpToMessage(input: { conversationId: number, messageId: number }) {
  contextOpen.value = false
  await router.push(communicationConversationMessagePath(input.conversationId, input.messageId))
}

function toggleContext() {
  contextOpen.value = !contextOpen.value
}

async function send(
  payload: CommunicationComposerPayload,
  acknowledge?: (ok: boolean) => void
) {
  const ok = await workspace.sendMessage(payload)
  acknowledge?.(ok)
}

function loadOlderMessages(acknowledge: (ok: boolean) => void) {
  const conversationId = workspace.selectedConversationId.value
  if (conversationId === null) {
    acknowledge(false)
    return
  }
  void workspace.loadOlderConversationMessages(conversationId)
    .then(acknowledge)
    .catch(() => acknowledge(false))
}

function loadNewerMessages(acknowledge: (ok: boolean) => void) {
  const conversationId = workspace.selectedConversationId.value
  if (conversationId === null) {
    acknowledge(false)
    return
  }
  void workspace.loadNewerConversationMessages(conversationId)
    .then(acknowledge)
    .catch(() => acknowledge(false))
}

function acknowledgeTimeline(state: {
  conversationId: number
  rendered: boolean
  visible: boolean
  atEnd: boolean
}) {
  void workspace.acknowledgeConversationTimeline(state)
}

async function editMessage(
  message: CommunicationMessage,
  text: string,
  acknowledge?: (ok: boolean) => void
) {
  const ok = await workspace.editMessage(message.id, text)
  acknowledge?.(ok)
}

function revokeMessage(message: CommunicationMessage) {
  void workspace.revokeMessage(message.id)
}

function reactMessage(message: CommunicationMessage, emoji: string | null) {
  void workspace.reactMessage(message.id, emoji)
}

function votePoll(message: CommunicationMessage, optionNames: string[]) {
  void workspace.votePoll(message.id, optionNames)
}

function sendReceipt(message: CommunicationMessage, receipt: 'READ' | 'PLAYED') {
  void workspace.sendReceipt(message.id, receipt)
}

function recoverMessage(
  message: CommunicationMessage,
  operation: 'UNAVAILABLE' | 'MEDIA_RETRY'
) {
  void workspace.recoverMessage(message.id, operation)
}

function updateConversation(patch: Record<string, unknown>) {
  void workspace.updateConversation(patch as Parameters<typeof workspace.updateConversation>[0])
}

async function downloadAttachment(
  _message: CommunicationMessage,
  attachmentId: number,
  filename: string
) {
  await download.download(
    api.communication.attachments.downloadUrl(attachmentId),
    filename
  )
}

async function exportContact(contactId: number) {
  if (!workspace.canManageContacts.value) return
  await download.download(
    api.communication.contacts.exportUrl(contactId),
    `contato-${contactId}.json`
  )
}

function requestPurge(contactId: number) {
  if (!workspace.canManageContacts.value) return
  purgeContactId.value = contactId
  purgeOpen.value = true
}

async function confirmPurge() {
  if (!workspace.canManageContacts.value || !purgeContactId.value) return
  purging.value = true
  try {
    await api.communication.contacts.purge(purgeContactId.value)
    toast.add({
      title: 'Dados de comunicação expurgados',
      description: 'Corpos e anexos foram removidos; o tombstone auditável foi preservado.',
      color: 'success'
    })
    purgeOpen.value = false
    contextOpen.value = false
    await clearConversationSelection()
    await workspace.initialize()
  } catch (caught) {
    toast.add({ title: apiErrorMessage(caught, 'Falha ao expurgar os dados.'), color: 'error' })
  } finally {
    purging.value = false
  }
}

function selectRelative(delta: number) {
  const items = workspace.conversations.value
  if (!items.length) return
  const current = items.findIndex(item => item.id === workspace.selectedConversationId.value)
  const index = current < 0
    ? (delta > 0 ? 0 : items.length - 1)
    : Math.max(0, Math.min(items.length - 1, current + delta))
  const target = items[index]
  if (target) void openConversation(target.id)
}

function isEditableShortcutTarget(event?: KeyboardEvent): boolean {
  const target = event?.target instanceof Element
    ? event.target
    : document.activeElement
  if (!(target instanceof Element)) return false
  return Boolean(target.closest([
    'input',
    'textarea',
    'select',
    '[role="combobox"]',
    '[contenteditable]:not([contenteditable="false"])',
    '[data-communication-composer]'
  ].join(',')))
}

function selectRelativeOutsideEditor(delta: number, event?: KeyboardEvent): void {
  if (isEditableShortcutTarget(event)) return
  selectRelative(delta)
}

defineShortcuts({
  arrowdown: {
    usingInput: false,
    handler: event => selectRelativeOutsideEditor(1, event)
  },
  arrowup: {
    usingInput: false,
    handler: event => selectRelativeOutsideEditor(-1, event)
  },
  escape: () => {
    if (contextOpen.value) contextOpen.value = false
    else if (isMobile.value) void clearConversationSelection()
  }
})

watch(
  () => [
    workspace.initialized.value,
    workspace.canView.value,
    routeConversationId.value,
    routeMessageId.value
  ] as const,
  ([initialized, canView, id]) => {
    if (!initialized || !canView) return
    void applyRouteConversation(id)
  },
  { immediate: true }
)

watch(routeContactId, (contactId) => {
  workspace.contactIdFilter.value = contactId
}, { immediate: true })

onMounted(() => {
  void workspace.initialize()
})

watch(
  () => workspace.contactIdFilter.value,
  () => {
    void loadContactFilterName()
  },
  { immediate: true }
)
onBeforeUnmount(() => {
  if (mobileFocusRestoreTimer !== null) clearTimeout(mobileFocusRestoreTimer)
  workspace.dispose()
})
</script>

<template>
  <template v-if="workspace.canView.value">
    <UDashboardPanel
      id="communication-list"
      data-testid="communication-list-panel"
      :default-size="24"
      :min-size="20"
      :max-size="32"
      resizable
    >
      <ShellPageNavbar title="Atendimento">
        <template #trailing>
          <UTooltip :text="conversationTotalLabel">
            <UBadge
              :label="String(workspace.conversationsTotal.value)"
              variant="subtle"
            />
          </UTooltip>
          <UTooltip :text="COMMUNICATION_REALTIME_META[workspace.realtimeState.value].label">
            <span
              class="inline-flex size-6 items-center justify-center rounded-full"
              role="status"
              :aria-label="`Atualização em tempo real: ${COMMUNICATION_REALTIME_META[workspace.realtimeState.value].label}`"
              data-testid="communication-realtime-status"
            >
              <UIcon
                :name="COMMUNICATION_REALTIME_META[workspace.realtimeState.value].icon"
                class="size-3.5"
                :class="realtimeStatusClass"
                aria-hidden="true"
              />
              <span class="sr-only">
                Atualização em tempo real: {{ COMMUNICATION_REALTIME_META[workspace.realtimeState.value].label }}
              </span>
            </span>
          </UTooltip>
        </template>
        <template #right>
          <UTooltip text="Nova conversa">
            <UButton
              v-if="workspace.canReply.value"
              icon="i-lucide-message-circle-plus"
              color="neutral"
              variant="ghost"
              aria-label="Nova conversa"
              :loading="newConversationLoading"
              @click="openNewConversation"
            />
          </UTooltip>
          <UDropdownMenu
            :items="navbarMenuItems"
            :content="{
              align: 'end',
              side: 'bottom',
              sideOffset: 6,
              collisionPadding: 8
            }"
          >
            <UButton
              icon="i-lucide-ellipsis-vertical"
              color="neutral"
              variant="ghost"
              aria-label="Mais ações do Atendimento"
              data-testid="communication-navbar-more"
            />
          </UDropdownMenu>
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar
        class="min-h-0 flex-col items-stretch justify-start gap-0 overflow-x-hidden border-b border-default px-0 sm:px-0"
      >
        <CommunicationConversationListFilters
          v-model:search="workspace.search.value"
          v-model:status="statusSelection"
          v-model:inbox-id="inboxSelection"
          v-model:assignee-id="assigneeSelection"
          v-model:department-id="departmentSelection"
          v-model:label-ids="labelSelection"
          v-model:sort-by="sortSelection"
          v-model:unassigned-only="unassignedOnlySelection"
          v-model:unread-only="workspace.unreadOnly.value"
          :contact-filter-label="workspace.contactIdFilter.value
            ? (contactFilterName || 'Contato selecionado')
            : null"
          :inbox-items="inboxItems"
          :assignee-items="assigneeItems"
          :department-items="departmentItems"
          :label-items="labelItems"
          :selection-active="workspace.selectedConversationCount.value > 0"
          @apply-quick-view="applyQuickView"
          @clear-contact="clearContactFilter"
        >
          <template #selection>
            <div
              class="flex h-10 w-full min-w-0 items-center gap-1 bg-elevated/30 px-2 [@media(pointer:coarse)]:h-12"
              role="group"
              aria-label="Ações em massa das conversas selecionadas"
              data-testid="communication-bulk-bar"
            >
              <UCheckbox
                :model-value="workspace.allLoadedSelected.value
                  ? true
                  : (workspace.selectionIndeterminate.value ? 'indeterminate' : false)"
                :aria-label="bulkSelectionToggleLabel"
                :title="bulkSelectionToggleLabel"
                data-testid="communication-bulk-select-all"
                @update:model-value="onBulkSelectAllChange"
              />

              <UKbd
                class="shrink-0 tabular-nums"
                data-testid="communication-bulk-count"
              >
                {{ workspace.selectedConversationCount.value }}
              </UKbd>
              <span
                class="min-w-0 truncate text-xs font-medium text-toned"
                data-testid="communication-bulk-selection-label"
              >
                {{ workspace.selectedConversationCount.value === 1 ? 'selecionada' : 'selecionadas' }}
              </span>

              <div class="ms-auto flex shrink-0 items-center justify-end gap-0.5">
                <UDropdownMenu
                  v-for="menu in bulkActionMenus"
                  :key="menu.key"
                  :items="menu.items"
                  :content="{
                    align: menu.align,
                    side: 'bottom',
                    sideOffset: 6,
                    collisionPadding: 8
                  }"
                >
                  <UTooltip :text="menu.label">
                    <UButton
                      :icon="menu.icon"
                      color="neutral"
                      variant="ghost"
                      size="xs"
                      square
                      class="[@media(pointer:coarse)]:size-11"
                      :loading="workspace.bulkSubmitting.value"
                      :disabled="workspace.bulkSubmitting.value"
                      :aria-label="menu.label"
                      :title="menu.label"
                      :data-testid="`communication-bulk-menu-${menu.key}`"
                    />
                  </UTooltip>
                </UDropdownMenu>

                <UTooltip text="Limpar seleção">
                  <UButton
                    icon="i-lucide-x"
                    color="neutral"
                    variant="ghost"
                    size="xs"
                    square
                    class="[@media(pointer:coarse)]:size-11"
                    :disabled="workspace.bulkSubmitting.value"
                    aria-label="Limpar seleção"
                    title="Limpar seleção"
                    data-testid="communication-bulk-clear"
                    @click="clearBulkSelection"
                  />
                </UTooltip>
              </div>
            </div>
          </template>
        </CommunicationConversationListFilters>

        <span
          class="sr-only"
          role="status"
          aria-live="polite"
          aria-atomic="true"
          data-testid="communication-bulk-announcement"
        >
          {{ bulkSelectionAnnouncement }}
        </span>
      </UDashboardToolbar>

      <UAlert
        v-if="workspace.error.value"
        :title="workspace.error.value"
        :actions="[{
          label: 'Tentar novamente',
          color: 'neutral',
          variant: 'subtle',
          onClick: () => workspace.initialize()
        }]"
        color="error"
        variant="subtle"
        class="m-3"
      />
      <UAlert
        v-else-if="workspace.syncError.value"
        :title="workspace.syncError.value"
        description="A lista permanece disponível. Tente sincronizar novamente sem recarregar a página."
        :actions="[{
          label: 'Tentar novamente',
          color: 'neutral',
          variant: 'subtle',
          onClick: () => workspace.synchronize()
        }]"
        color="warning"
        variant="subtle"
        class="m-3"
      />

      <CommunicationConversationList
        ref="conversationListRef"
        :conversations="workspace.conversations.value"
        :inboxes="workspace.inboxes.value"
        :departments="workspace.departments.value"
        :labels="workspace.labels.value"
        :selected-id="workspace.selectedConversationId.value"
        :opening-id="workspace.openingConversationId.value"
        :selected-ids="workspace.selectedConversationIds.value"
        :loading="workspace.conversationsInitialLoading.value"
        :empty="workspace.conversationsEmpty.value"
        :has-more="workspace.conversationsHasMore.value"
        :loading-more="workspace.conversationsLoadingMore.value"
        :load-more-error="workspace.conversationsLoadMoreError.value"
        :total="workspace.conversationsTotal.value"
        :can-view="workspace.canView.value"
        :can-reply="workspace.canReply.value"
        :action-disabled="conversationActionPending"
        @select="selectConversation"
        @prefetch="prefetchConversation"
        @load-more="workspace.loadMoreConversations"
        @toggle-select="onToggleSelect"
        @action="onConversationAction"
      />
    </UDashboardPanel>

    <CommunicationTimelinePanel
      v-if="workspace.selectedConversation.value"
      class="hidden lg:flex"
      :conversation="workspace.selectedConversation.value"
      :inbox="workspace.selectedInbox.value"
      :signals="workspace.selectedSignals.value"
      :canned-responses="workspace.cannedResponses.value"
      :departments="workspace.departments.value"
      :labels="workspace.labels.value"
      :can-view="workspace.canView.value"
      :can-reply="workspace.canReply.value"
      :operational="workspace.communicationOperational.value"
      :outbound-operational="workspace.outboundOperational.value"
      :unavailable-reason="workspace.communicationBlockReason.value"
      :sending="workspace.sending.value"
      :action-loading-id="workspace.messageActionLoadingId.value"
      :context-open="contextOpen"
      :timeline="workspace.selectedTimeline.value"
      :viewport-active="!isMobile"
      :highlighted-message-id="routeMessageId"
      :action-disabled="conversationActionPending"
      @action="onConversationAction"
      @send="send"
      @toggle-context="toggleContext"
      @download="downloadAttachment"
      @edit="editMessage"
      @revoke="revokeMessage"
      @react="reactMessage"
      @vote="votePoll"
      @receipt="sendReceipt"
      @recover="recoverMessage"
      @presence="workspace.setChatPresence"
      @load-older="loadOlderMessages"
      @load-newer="loadNewerMessages"
      @timeline-state="acknowledgeTimeline"
    />

    <CommunicationContextPanel
      v-if="workspace.selectedConversation.value && contextOpen"
      class="hidden 2xl:flex"
      :conversation="workspace.selectedConversation.value"
      :inbox="workspace.selectedInbox.value"
      :labels="workspace.labels.value"
      :departments="workspace.departments.value"
      :can-reply="workspace.canReply.value"
      :can-manage-contacts="workspace.canManageContacts.value"
      :outbound-operational="workspace.outboundOperational.value"
      :signals="workspace.selectedSignals.value"
      @close="contextOpen = false"
      @update="updateConversation"
      @toggle-label="workspace.toggleLabel"
      @export-contact="exportContact"
      @purge-contact="requestPurge"
      @set-disappearing="workspace.setDisappearingTimer"
      @jump-to-message="jumpToMessage"
    />

    <div
      v-if="!workspace.selectedConversation.value"
      class="hidden min-w-0 flex-1 flex-col items-center justify-center gap-4 lg:flex"
      data-testid="communication-empty-detail"
      :role="workspace.openingConversationId.value ? 'status' : undefined"
      :aria-live="workspace.openingConversationId.value ? 'polite' : undefined"
    >
      <UIcon
        :name="workspace.openingConversationId.value
          ? 'i-lucide-message-square-more'
          : 'i-lucide-message-square-dashed'"
        class="size-24 text-dimmed"
      />
      <div class="text-center">
        <p class="font-medium text-highlighted">
          {{ workspace.openingConversationId.value ? 'Abrindo conversa' : 'Selecione uma conversa' }}
        </p>
        <p class="mt-1 text-sm text-muted">
          {{ workspace.openingConversationId.value
            ? 'O histórico aparecerá assim que estiver pronto.'
            : 'Use ↑ e ↓ para navegar pela fila.' }}
        </p>
      </div>
    </div>

    <ClientOnly>
      <USlideover
        v-if="isMobile"
        v-model:open="mobileConversationOpen"
        data-testid="communication-mobile-timeline"
        :ui="{ content: 'w-screen max-w-none' }"
        @after:leave="restoreConversationFocusAfterLeave"
      >
        <template #content>
          <CommunicationTimelinePanel
            v-if="workspace.selectedConversation.value"
            mobile
            :conversation="workspace.selectedConversation.value"
            :inbox="workspace.selectedInbox.value"
            :signals="workspace.selectedSignals.value"
            :canned-responses="workspace.cannedResponses.value"
            :departments="workspace.departments.value"
            :labels="workspace.labels.value"
            :can-view="workspace.canView.value"
            :can-reply="workspace.canReply.value"
            :operational="workspace.communicationOperational.value"
            :outbound-operational="workspace.outboundOperational.value"
            :unavailable-reason="workspace.communicationBlockReason.value"
            :sending="workspace.sending.value"
            :action-loading-id="workspace.messageActionLoadingId.value"
            :context-open="contextOpen"
            :timeline="workspace.selectedTimeline.value"
            :viewport-active="mobileConversationOpen"
            :highlighted-message-id="routeMessageId"
            :action-disabled="conversationActionPending"
            @action="onConversationAction"
            @close="closeMobileConversation"
            @send="send"
            @toggle-context="toggleContext"
            @download="downloadAttachment"
            @edit="editMessage"
            @revoke="revokeMessage"
            @react="reactMessage"
            @vote="votePoll"
            @receipt="sendReceipt"
            @recover="recoverMessage"
            @presence="workspace.setChatPresence"
            @load-older="loadOlderMessages"
            @load-newer="loadNewerMessages"
            @timeline-state="acknowledgeTimeline"
          />
        </template>
      </USlideover>

      <USlideover
        v-if="usesContextSlideover"
        v-model:open="contextOpen"
        data-testid="communication-context-slideover"
        :ui="{ content: 'w-screen max-w-md' }"
      >
        <template #content>
          <CommunicationContextPanel
            v-if="workspace.selectedConversation.value"
            mobile
            :conversation="workspace.selectedConversation.value"
            :inbox="workspace.selectedInbox.value"
            :labels="workspace.labels.value"
            :departments="workspace.departments.value"
            :can-reply="workspace.canReply.value"
            :can-manage-contacts="workspace.canManageContacts.value"
            :outbound-operational="workspace.outboundOperational.value"
            :signals="workspace.selectedSignals.value"
            @close="contextOpen = false"
            @update="updateConversation"
            @toggle-label="workspace.toggleLabel"
            @export-contact="exportContact"
            @purge-contact="requestPurge"
            @set-disappearing="workspace.setDisappearingTimer"
            @jump-to-message="jumpToMessage"
          />
        </template>
      </USlideover>
    </ClientOnly>

    <CommunicationAdministrationSlideover
      v-if="workspace.canManage.value"
      v-model:open="administrationOpen"
    />

    <CommunicationNewConversationModal
      v-model:open="newConversationOpen"
      :contact="newConversationContact"
      :contacts="newConversationContacts"
      :inboxes="workspace.inboxes.value"
      :can-reply="workspace.canReply.value"
      @created="onConversationCreated"
    />

    <ShellConfirmModal
      v-model:open="purgeOpen"
      title="Expurgar dados deste contato?"
      description="Esta ação remove definitivamente mensagens e anexos recuperáveis."
      tone="danger"
      confirm-label="Expurgar definitivamente"
      confirm-icon="i-lucide-trash-2"
      :loading="purging"
      test-id="communication-purge-confirm"
      @cancel="closePurge"
      @confirm="confirmPurge"
    >
      <template #body>
        <UAlert
          title="Apenas o tombstone sanitizado e o ledger de auditoria serão preservados."
          color="error"
          icon="i-lucide-triangle-alert"
          variant="subtle"
        />
      </template>
    </ShellConfirmModal>
  </template>

  <UDashboardPanel v-else id="communication-forbidden">
    <UDashboardNavbar title="Atendimento">
      <template #leading>
        <UDashboardSidebarCollapse />
      </template>
    </UDashboardNavbar>
    <div class="flex flex-1 items-center justify-center p-6">
      <UAlert
        title="Acesso ao atendimento não autorizado"
        description="Solicite a permissão communication.view a um administrador do escritório."
        color="warning"
        icon="i-lucide-shield-x"
        variant="subtle"
        class="max-w-lg"
      />
    </div>
  </UDashboardPanel>
</template>
