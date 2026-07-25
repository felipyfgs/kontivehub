import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest'
import {
  consumeMailboxClientFilterHandoff,
  setMailboxClientFilterHandoff,
  peekMailboxClientFilterHandoff
} from '../../app/utils/mailbox-handoff'
import { mailboxTriageLabel, parseMailboxTriageStatus } from '../../app/utils/mailbox-triage'
import { parseMailboxBodyPreviewBlob } from '../../app/utils/mailbox-body-preview'

describe('mailbox-handoff', () => {
  const store = new Map<string, string>()

  beforeEach(() => {
    store.clear()
    vi.stubGlobal('sessionStorage', {
      getItem: (k: string) => store.get(k) ?? null,
      setItem: (k: string, v: string) => { store.set(k, v) },
      removeItem: (k: string) => { store.delete(k) }
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('stores and consumes client filter handoff', () => {
    setMailboxClientFilterHandoff(42)
    expect(peekMailboxClientFilterHandoff()?.clientId).toBe(42)
    expect(consumeMailboxClientFilterHandoff()).toBe(42)
    expect(consumeMailboxClientFilterHandoff()).toBeNull()
  })

  it('ignores expired handoff', () => {
    setMailboxClientFilterHandoff(7)
    const raw = store.get('fiscal.mailbox.clientFilterHandoff')!
    const parsed = JSON.parse(raw) as { clientId: number, setAt: number }
    store.set('fiscal.mailbox.clientFilterHandoff', JSON.stringify({
      ...parsed,
      setAt: Date.now() - 10 * 60 * 1000
    }))
    expect(consumeMailboxClientFilterHandoff()).toBeNull()
  })

  it('ignores invalid clientId', () => {
    setMailboxClientFilterHandoff(0)
    expect(peekMailboxClientFilterHandoff()).toBeNull()
  })
})

describe('mailbox-triage labels', () => {
  it('maps statuses to pt-BR', () => {
    expect(mailboxTriageLabel('NEW')).toBe('Nova')
    expect(mailboxTriageLabel('IN_REVIEW')).toBe('Em análise')
    expect(mailboxTriageLabel('RESOLVED')).toBe('Resolvida')
    expect(parseMailboxTriageStatus('new')).toBe('NEW')
  })
})

describe('mailbox-body-preview', () => {
  it('parses plain text body', async () => {
    const blob = new Blob(['Olá contribuinte'], { type: 'text/plain' })
    const result = await parseMailboxBodyPreviewBlob(blob)
    expect(result.ok).toBe(true)
    if (result.ok) {
      expect(result.text).toBe('Olá contribuinte')
    }
  })

  it('treats json error payload as failure', async () => {
    const blob = new Blob([JSON.stringify({ message: 'Corpo da mensagem não disponível.' })], {
      type: 'application/json'
    })
    const result = await parseMailboxBodyPreviewBlob(blob)
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(result.error).toContain('não disponível')
    }
  })
})
