import type {
  ConversationSavedViewPayload,
  SavedListFilterPayload
} from '~/types/saved-list-filters'
import type {
  ConversationSortBy,
  ListPreferenceStatus
} from '~/types/communication/conversations'
import { normalizeCommunicationConversationSortBy } from '~/utils/communication-conversation-sort'

const SAVED_VIEW_FIELDS = new Set([
  'status',
  'sort_by',
  'inbox_id',
  'assignee_membership_id',
  'work_department_id',
  'label_ids',
  'unread',
  'unassigned'
])

export type ConversationSavedViewState = {
  status: ListPreferenceStatus
  sortBy: ConversationSortBy
  inboxId?: number | null
  assigneeMembershipId?: number | null
  workDepartmentId?: number | null
  labelIds?: number[]
  unread?: boolean
  unassigned?: boolean
}

export type ConversationSavedViewCatalogs = {
  inboxIds: readonly number[]
  assigneeMembershipIds: readonly number[]
  workDepartmentIds: readonly number[]
  labelIds: readonly number[]
}

export function buildConversationSavedViewPayload(
  state: ConversationSavedViewState
): ConversationSavedViewPayload {
  const payload: ConversationSavedViewPayload = {
    status: state.status,
    sort_by: normalizeCommunicationConversationSortBy(state.sortBy)
  }

  if (positiveId(state.inboxId)) payload.inbox_id = state.inboxId
  if (positiveId(state.assigneeMembershipId) && !state.unassigned) {
    payload.assignee_membership_id = state.assigneeMembershipId
  }
  if (positiveId(state.workDepartmentId)) {
    payload.work_department_id = state.workDepartmentId
  }
  if (state.labelIds?.length) payload.label_ids = [...new Set(state.labelIds)]
  if (state.unread) payload.unread = true
  if (state.unassigned) payload.unassigned = true

  return payload
}

export function asConversationSavedViewPayload(
  payload: SavedListFilterPayload | unknown
): ConversationSavedViewPayload | null {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return null
  const candidate = payload as Record<string, unknown>
  if (Object.keys(candidate).some(key => !SAVED_VIEW_FIELDS.has(key))) return null

  const statuses = ['ALL', 'OPEN', 'PENDING', 'RESOLVED', 'SNOOZED']
  if (!statuses.includes(String(candidate.status))) return null
  const sortBy = normalizeCommunicationConversationSortBy(candidate.sort_by)
  if (sortBy !== candidate.sort_by) return null

  for (const field of ['inbox_id', 'assignee_membership_id', 'work_department_id']) {
    const value = candidate[field]
    if (value !== undefined && !positiveId(value)) return null
  }
  if (candidate.label_ids !== undefined) {
    if (!Array.isArray(candidate.label_ids)
      || candidate.label_ids.some(id => !positiveId(id))) return null
  }
  for (const field of ['unread', 'unassigned']) {
    const value = candidate[field]
    if (value !== undefined && typeof value !== 'boolean') return null
  }

  return candidate as ConversationSavedViewPayload
}

export function conversationSavedViewUnavailableReason(
  payload: SavedListFilterPayload | unknown,
  catalogs: ConversationSavedViewCatalogs
): string | null {
  const view = asConversationSavedViewPayload(payload)
  if (!view) return 'O formato desta visão não é compatível com a versão atual.'
  if (!contains(catalogs.inboxIds, view.inbox_id)) {
    return 'A inbox salva não está mais disponível para este operador.'
  }
  if (!contains(catalogs.assigneeMembershipIds, view.assignee_membership_id)) {
    return 'O responsável salvo não está mais disponível para este operador.'
  }
  if (!contains(catalogs.workDepartmentIds, view.work_department_id)) {
    return 'A fila salva não está mais disponível para este operador.'
  }
  if (view.label_ids?.some(id => !catalogs.labelIds.includes(id))) {
    return 'Um marcador salvo não está mais disponível para este operador.'
  }

  return null
}

function positiveId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

function contains(ids: readonly number[], id: number | undefined): boolean {
  return id === undefined || ids.includes(id)
}
