import type {
  CommunicationConversationQuickView,
  CommunicationConversationStatus
} from '~/types/communication'

export type CommunicationFixedQuickView = Extract<
  CommunicationConversationQuickView,
  'OPEN' | 'UNASSIGNED' | 'UNREAD'
>

export interface CommunicationQuickViewState {
  status: CommunicationConversationStatus | null
  unreadOnly: boolean
  unassignedOnly: boolean
}

export const COMMUNICATION_CONVERSATION_QUICK_VIEW_TABS = [
  { label: 'Em aberto', value: 'OPEN' },
  { label: 'Não lidas', value: 'UNREAD' },
  { label: 'Não atribuídas', compactLabel: 'Não atrib.', value: 'UNASSIGNED' }
] as const satisfies ReadonlyArray<{
  label: string
  compactLabel?: string
  value: CommunicationFixedQuickView
}>

export function communicationQuickViewState(
  view: CommunicationConversationQuickView
): CommunicationQuickViewState {
  if (view === 'UNREAD') {
    return { status: 'OPEN', unreadOnly: true, unassignedOnly: false }
  }
  if (view === 'UNASSIGNED') {
    return { status: 'OPEN', unreadOnly: false, unassignedOnly: true }
  }
  return {
    status: view === 'ALL' ? null : view,
    unreadOnly: false,
    unassignedOnly: false
  }
}

export function activeCommunicationQuickView(
  state: CommunicationQuickViewState
): CommunicationConversationQuickView | null {
  if (state.status !== 'OPEN') return null
  if (state.unreadOnly && !state.unassignedOnly) return 'UNREAD'
  if (state.unassignedOnly && !state.unreadOnly) return 'UNASSIGNED'
  if (!state.unreadOnly && !state.unassignedOnly) return 'OPEN'
  return null
}
