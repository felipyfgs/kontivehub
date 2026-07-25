import { describe, expect, it } from 'vitest'
import {
  communicationPairingDeadline,
  communicationPairingEvent,
  isCommunicationPairingActive,
  isCommunicationPairingTerminal
} from '~/utils/communication-pairing'

describe('ciclo finito do pairing WhatsApp', () => {
  it('preserva o deadline absoluto informado pela API', () => {
    const expiresAt = '2026-07-22T18:00:00.000Z'
    const fallback = Date.parse('2026-07-22T18:02:00.000Z')

    expect(communicationPairingDeadline({ event: 'pending', expires_at: expiresAt }, fallback))
      .toBe(Date.parse(expiresAt))
    expect(communicationPairingDeadline({ event: 'pending' }, fallback)).toBe(fallback)
  })

  it('mantém pending e code ativos somente antes do mesmo deadline', () => {
    const deadline = 10_000

    expect(isCommunicationPairingActive({ event: 'pending' }, deadline, 9_999)).toBe(true)
    expect(isCommunicationPairingActive({ event: 'CODE' }, deadline, 9_999)).toBe(true)
    expect(isCommunicationPairingActive({ event: 'pending' }, deadline, 10_000)).toBe(false)
  })

  it('encerra sucesso, erro, timeout e falhas determinísticas', () => {
    for (const event of ['success', 'error', 'timeout', 'PAIR_FAILED', 'err-client-outdated']) {
      expect(isCommunicationPairingTerminal({ event })).toBe(true)
    }
    expect(communicationPairingEvent({ event: ' QR_AVAILABLE ' })).toBe('qr_available')
    expect(isCommunicationPairingTerminal({ event: 'pending' })).toBe(false)
  })
})
