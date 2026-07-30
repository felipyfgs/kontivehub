<script setup lang="ts">
import { breakpointsTailwind, useBreakpoints } from '@vueuse/core'
import type { DropdownMenuItem } from '@nuxt/ui'
import type {
  CommunicationBulkAction,
  CommunicationComposerPayload,
  CommunicationContact,
  CommunicationConversation,
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
  communicationConversationPath,
  parseCommunicationConversationId
} from '~/utils/communication-routes'
import { normalizeCommunicationConversationSortBy } from '~/utils/communication-conversation-sort'

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
const filtersHydrated = ref(false)
const contactFilterName = ref<string | null>(null)
const pendingConversationFocusId = ref<number | null>(null)
let mobileFocusRestoreTimer: ReturnType<typeof setTimeout> | null = null
let contactFilterRequestSequence = 0

const routeConversationId = computed(() => parseCommunicationConversationId(route.params.id))
const routeMessageId = computed(() => {
  const id = Number(route.query.message_id)
  return Number.isInteger(id) && id > 0 ? id : null
})

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
    workspace.assigneeFilter.value = value || null
    if (value) workspace.unassignedOnly.value = false
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

function scopeQueryFromWorkspace(): Record<string, string> {
  const query: Record<string, string> = {}
  if (workspace.inboxFilter.value) query.inbox_id = String(workspace.inboxFilter.value)
  if (workspace.assigneeFilter.value) {
    query.assignee_membership_id = String(workspace.assigneeFilter.value)
  }
  if (workspace.departmentFilter.value) {
    query.work_department_id = String(workspace.departmentFilter.value)
  }
  if (workspace.unassignedOnly.value) query.unassigned = '1'
  if (workspace.unreadOnly.value) query.unread = '1'
  if (workspace.labelIdsFilter.value.length) {
    query.label_ids = workspace.labelIdsFilter.value.join(',')
  }
  if (workspace.contactIdFilter.value) query.contact_id = String(workspace.contactIdFilter.value)
  // status/sort ficam na preferência do servidor; q só em memória.
  return query
}

function scopeQueryForCurrentRoute(conversationId: number | null): Record<string, string> {
  const query = scopeQueryFromWorkspace()
  if (conversationId !== null
    && routeConversationId.value === conversationId
    && routeMessageId.value !== null) {
    query.message_id = String(routeMessageId.value)
  }
  return query
}

function sameRouteQuery(current: Record<string, unknown>, next: Record<string, string>): boolean {
  const normalize = (query: Record<string, unknown>) => Object.entries(query)
    .filter(([, value]) => value !== undefined)
    .map(([key, value]) => [key, Array.isArray(value) ? value.map(String).sort() : String(value)] as const)
    .sort(([left], [right]) => left.localeCompare(right))
  return JSON.stringify(normalize(current)) === JSON.stringify(normalize(next))
}

function applyScopeQueryFromRoute(): void {
  const q = route.query
  const inbox = Number(q.inbox_id)
  workspace.inboxFilter.value = Number.isInteger(inbox) && inbox > 0 ? inbox : null
  const assignee = Number(q.assignee_membership_id)
  workspace.assigneeFilter.value = Number.isInteger(assignee) && assignee > 0 ? assignee : null
  const department = Number(q.work_department_id)
  workspace.departmentFilter.value = Number.isInteger(department) && department > 0
    ? department
    : null
  workspace.unassignedOnly.value = q.unassigned === '1' || q.unassigned === 'true'
  workspace.unreadOnly.value = q.unread === '1' || q.unread === 'true'
  workspace.labelIdsFilter.value = typeof q.label_ids === 'string' && q.label_ids.trim()
    ? q.label_ids
        .split(',')
        .map(part => Number(part))
        .filter(id => Number.isInteger(id) && id > 0)
    : []
  const contactId = Number(q.contact_id)
  workspace.contactIdFilter.value = Number.isInteger(contactId) && contactId > 0 ? contactId : null
  filtersHydrated.value = true
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
  const target = id === null ? COMMUNICATION_INDEX_PATH : communicationConversationPath(id)
  const query = scopeQueryForCurrentRoute(id)
  const samePath = route.path === target
  const sameQuery = sameRouteQuery(route.query, query)
  if (samePath && sameQuery) return
  await router.push({ path: target, query })
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

function onToggleSelectAll(selected: boolean): void {
  workspace.toggleSelectAllLoaded(selected)
}

async function onRowAction(payload: {
  conversation: CommunicationConversation
  action: CommunicationBulkAction | 'OPEN'
  params?: {
    status?: CommunicationConversationStatus
    snoozed_until?: string | null
  }
}): Promise<void> {
  const { conversation, action, params } = payload
  if (action === 'OPEN') {
    await openConversation(conversation.id)
    return
  }
  // Menu da linha: endpoints unitários (não limpam a seleção operacional).
  try {
    if (action === 'MARK_READ') {
      await workspace.markConversationRead(conversation.id)
      return
    }
    if (action === 'MARK_UNREAD') {
      await workspace.markConversationUnread(conversation.id)
      return
    }
    if (action === 'SET_STATUS' && params?.status) {
      await workspace.updateConversation({
        status: params.status,
        snoozed_until: params.status === 'SNOOZED'
          ? params.snoozed_until ?? null
          : null
      }, conversation)
      await workspace.reloadConversations()
      return
    }
    toast.add({
      title: 'Ação de linha não suportada.',
      color: 'warning'
    })
  } catch (caught) {
    toast.add({
      title: apiErrorMessage(caught, 'Falha ao aplicar a ação na conversa.'),
      color: 'error'
    })
  }
}

const bulkMenuItems = computed((): DropdownMenuItem[][] => {
  const readGroup: DropdownMenuItem[] = []
  if (workspace.canView.value) {
    readGroup.push(
      {
        label: 'Marcar como lidas',
        icon: 'i-lucide-mail-open',
        onSelect: () => void workspace.submitBulkOperation('MARK_READ')
      },
      {
        label: 'Marcar como não lidas',
        icon: 'i-lucide-mail',
        onSelect: () => void workspace.submitBulkOperation('MARK_UNREAD')
      }
    )
  }
  const operationGroup: DropdownMenuItem[] = []
  if (workspace.canReply.value) {
    operationGroup.push({
      label: 'Alterar status',
      icon: 'i-lucide-list-checks',
      children: [
        {
          label: 'Resolver',
          icon: 'i-lucide-circle-check',
          onSelect: () => void workspace.submitBulkOperation('SET_STATUS', { status: 'RESOLVED' })
        },
        {
          label: 'Pendente',
          icon: 'i-lucide-clock-3',
          onSelect: () => void workspace.submitBulkOperation('SET_STATUS', { status: 'PENDING' })
        },
        {
          label: 'Reabrir',
          icon: 'i-lucide-rotate-ccw',
          onSelect: () => void workspace.submitBulkOperation('SET_STATUS', { status: 'OPEN' })
        },
        {
          label: 'Adiar 1 hora',
          icon: 'i-lucide-alarm-clock',
          onSelect: () => void workspace.submitBulkOperation('SET_STATUS', {
            status: 'SNOOZED',
            snoozed_until: communicationSnoozeUntil(1)
          })
        },
        {
          label: 'Adiar até amanhã 9h',
          icon: 'i-lucide-sunrise',
          onSelect: () => void workspace.submitBulkOperation('SET_STATUS', {
            status: 'SNOOZED',
            snoozed_until: communicationSnoozeTomorrowMorning()
          })
        }
      ]
    })
    operationGroup.push({
      label: 'Atribuir responsável',
      icon: 'i-lucide-user-check',
      children: [
        {
          label: 'Sem responsável',
          icon: 'i-lucide-user-x',
          onSelect: () => void workspace.submitBulkOperation('SET_ASSIGNEE', {
            assignee_membership_id: null
          })
        },
        ...workspace.tenantMembers.value.map(member => ({
          label: member.name || member.email || `Membro #${member.id}`,
          icon: 'i-lucide-user-check',
          onSelect: () => void workspace.submitBulkOperation('SET_ASSIGNEE', {
            assignee_membership_id: member.id
          })
        }))
      ]
    })
    operationGroup.push({
      label: 'Mover para fila',
      icon: 'i-lucide-folders',
      children: [
        {
          label: 'Sem fila',
          icon: 'i-lucide-folder-x',
          onSelect: () => void workspace.submitBulkOperation('SET_DEPARTMENT', {
            work_department_id: null
          })
        },
        ...workspace.departments.value.map(department => ({
          label: department.name,
          icon: 'i-lucide-folders',
          onSelect: () => void workspace.submitBulkOperation('SET_DEPARTMENT', {
            work_department_id: department.id
          })
        }))
      ]
    })
    if (workspace.labels.value.length) {
      operationGroup.push(
        {
          label: 'Adicionar marcador',
          icon: 'i-lucide-tag',
          children: workspace.labels.value.map(label => ({
            label: label.name,
            icon: 'i-lucide-tag',
            onSelect: () => void workspace.submitBulkOperation('ADD_LABELS', {
              label_ids: [label.id]
            })
          }))
        },
        {
          label: 'Remover marcador',
          icon: 'i-lucide-tag',
          children: workspace.labels.value.map(label => ({
            label: label.name,
            icon: 'i-lucide-tag',
            onSelect: () => void workspace.submitBulkOperation('REMOVE_LABELS', {
              label_ids: [label.id]
            })
          }))
        }
      )
    }
  }
  return [readGroup, operationGroup].filter(group => group.length > 0)
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
        path: communicationConversationPath(id),
        query: scopeQueryFromWorkspace()
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
  await router.push({
    path: communicationConversationPath(input.conversationId),
    query: {
      ...scopeQueryFromWorkspace(),
      message_id: String(input.messageId)
    }
  })
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

// Sincroniza filtros de escopo não sensíveis na query (sem q).
watch(
  () => [
    workspace.inboxFilter.value,
    workspace.assigneeFilter.value,
    workspace.departmentFilter.value,
    workspace.unassignedOnly.value,
    workspace.unreadOnly.value,
    workspace.labelIdsFilter.value.join(','),
    workspace.contactIdFilter.value,
    filtersHydrated.value,
    workspace.initialized.value
  ] as const,
  () => {
    if (!filtersHydrated.value || !workspace.initialized.value) return
    const query = scopeQueryForCurrentRoute(routeConversationId.value)
    if (sameRouteQuery(route.query, query)) return
    void router.replace({ path: route.path, query })
  }
)

onMounted(() => {
  applyScopeQueryFromRoute()
  void loadContactFilterName()
  void workspace.initialize()
})

watch(
  () => workspace.contactIdFilter.value,
  () => {
    void loadContactFilterName()
  }
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
          <UBadge
            :label="String(workspace.conversations.value.length)"
            variant="subtle"
          />
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
          <UTooltip :text="COMMUNICATION_REALTIME_META[workspace.realtimeState.value].label">
            <UButton
              :icon="COMMUNICATION_REALTIME_META[workspace.realtimeState.value].icon"
              :color="COMMUNICATION_REALTIME_META[workspace.realtimeState.value].color"
              variant="ghost"
              aria-label="Estado da atualização em tempo real"
              :loading="workspace.syncing.value"
              @click="workspace.synchronize"
            />
          </UTooltip>
          <UButton
            v-if="workspace.canManage.value"
            icon="i-lucide-settings-2"
            color="neutral"
            variant="ghost"
            aria-label="Administrar comunicação"
            @click="openAdministration"
          />
        </template>
      </ShellPageNavbar>

      <UDashboardToolbar class="border-b border-default">
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
          :inbox-items="inboxItems"
          :assignee-items="assigneeItems"
          :department-items="departmentItems"
          :label-items="labelItems"
        />
        <UBadge
          v-if="workspace.contactIdFilter.value"
          color="primary"
          variant="subtle"
          class="ml-2"
          :label="`Contato: ${contactFilterName || 'Contato selecionado'}`"
        >
          <template #trailing>
            <UButton
              color="primary"
              variant="link"
              size="xs"
              square
              icon="i-lucide-x"
              aria-label="Remover filtro de contato"
              data-testid="communication-contact-filter-remove"
              @click="clearContactFilter"
            />
          </template>
        </UBadge>
      </UDashboardToolbar>

      <div
        v-if="workspace.selectedConversationCount.value > 0"
        class="flex shrink-0 flex-wrap items-center gap-2 border-b border-default px-3 py-2"
        data-testid="communication-bulk-bar"
      >
        <ShellBulkActionBar
          :selected-count="workspace.selectedConversationCount.value"
          :items="bulkMenuItems"
          :loading="workspace.bulkSubmitting.value"
          label="Ações"
          aria-label="Ações em massa nas conversas"
          test-id="communication-bulk-actions"
        />
        <UButton
          label="Limpar seleção"
          color="neutral"
          variant="ghost"
          size="sm"
          data-testid="communication-bulk-clear"
          @click="workspace.clearOperationalSelection()"
        />
        <span class="text-xs text-muted">
          {{ workspace.selectedConversationCount.value }} selecionada(s)
        </span>
      </div>

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
        :selected-id="workspace.selectedConversationId.value"
        :opening-id="workspace.openingConversationId.value"
        :selected-ids="workspace.selectedConversationIds.value"
        :all-loaded-selected="workspace.allLoadedSelected.value"
        :selection-indeterminate="workspace.selectionIndeterminate.value"
        :loading="workspace.conversationsInitialLoading.value"
        :empty="workspace.conversationsEmpty.value"
        :has-more="workspace.conversationsHasMore.value"
        :loading-more="workspace.conversationsLoadingMore.value"
        :load-more-error="workspace.conversationsLoadMoreError.value"
        :total="workspace.conversationsTotal.value"
        :can-view="workspace.canView.value"
        :can-reply="workspace.canReply.value"
        @select="selectConversation"
        @prefetch="prefetchConversation"
        @load-more="workspace.loadMoreConversations"
        @toggle-select="onToggleSelect"
        @toggle-select-all="onToggleSelectAll"
        @row-action="onRowAction"
      />
    </UDashboardPanel>

    <CommunicationTimelinePanel
      v-if="workspace.selectedConversation.value"
      class="hidden lg:flex"
      :conversation="workspace.selectedConversation.value"
      :inbox="workspace.selectedInbox.value"
      :signals="workspace.selectedSignals.value"
      :canned-responses="workspace.cannedResponses.value"
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
      @send="send"
      @update="updateConversation"
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
            @close="closeMobileConversation"
            @send="send"
            @update="updateConversation"
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
