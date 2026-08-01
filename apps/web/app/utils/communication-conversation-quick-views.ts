import type { ConversationQuickView, ConversationStatus } from '~/types/communication/conversations'

export type FixedQuickView = Extract<
  ConversationQuickView,
  'OPEN' | 'UNASSIGNED' | 'UNREAD'
>

export interface QuickViewState {
  status: ConversationStatus | null
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
  value: FixedQuickView
}>

export function communicationQuickViewState(
  view: ConversationQuickView
): QuickViewState {
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
  state: QuickViewState
): ConversationQuickView | null {
  if (state.status !== 'OPEN') return null
  if (state.unreadOnly && !state.unassignedOnly) return 'UNREAD'
  if (state.unassignedOnly && !state.unreadOnly) return 'UNASSIGNED'
  if (!state.unreadOnly && !state.unassignedOnly) return 'OPEN'
  return null
}
