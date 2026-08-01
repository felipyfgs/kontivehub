import type { PairingState } from '~/types/communication/inboxes'

const ACTIVE_PAIRING_EVENTS = new Set([
  'pending',
  'code',
  'qr',
  'qr_available',
  'phone-code',
  'passkey_required',
  'passkey_confirmation_required'
])

const TERMINAL_PAIRING_EVENTS = new Set([
  'success',
  'paired',
  'error',
  'timeout',
  'pair_failed',
  'passkey_failed',
  'err-unexpected-state',
  'err-client-outdated',
  'err-scanned-without-multidevice'
])

export function communicationPairingEvent(state: PairingState | null | undefined): string {
  return String(state?.event || '').trim().toLowerCase()
}

export function communicationPairingDeadline(
  state: PairingState | null | undefined,
  fallbackDeadline: number
): number {
  const parsed = state?.expires_at ? Date.parse(state.expires_at) : Number.NaN
  return Number.isFinite(parsed) ? parsed : fallbackDeadline
}

export function isCommunicationPairingTerminal(
  state: PairingState | null | undefined
): boolean {
  return TERMINAL_PAIRING_EVENTS.has(communicationPairingEvent(state))
}

export function isCommunicationPairingActive(
  state: PairingState | null | undefined,
  deadline: number,
  now = Date.now()
): boolean {
  return ACTIVE_PAIRING_EVENTS.has(communicationPairingEvent(state)) && deadline > now
}
