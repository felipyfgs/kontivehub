/**
 * Handoff em memória (sessionStorage) para filtrar a Caixa Postal por cliente
 * sem serializar query na URL Nuxt (monitoring-url-canonical path-only).
 */

const STORAGE_KEY = 'fiscal.mailbox.clientFilterHandoff'

export type MailboxClientFilterHandoff = {
  clientId: number
  /** Epoch ms — handoffs velhos são ignorados. */
  setAt: number
}

const MAX_AGE_MS = 5 * 60 * 1000

export function setMailboxClientFilterHandoff(clientId: number): void {
  if (!Number.isFinite(clientId) || clientId < 1) return
  if (typeof sessionStorage === 'undefined') return
  const payload: MailboxClientFilterHandoff = {
    clientId: Math.floor(clientId),
    setAt: Date.now()
  }
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload))
}

/** Consome e limpa o handoff; retorna clientId ou null. */
export function consumeMailboxClientFilterHandoff(now = Date.now()): number | null {
  if (typeof sessionStorage === 'undefined') return null
  const raw = sessionStorage.getItem(STORAGE_KEY)
  sessionStorage.removeItem(STORAGE_KEY)
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as Partial<MailboxClientFilterHandoff>
    const clientId = Number(parsed.clientId)
    const setAt = Number(parsed.setAt)
    if (!Number.isFinite(clientId) || clientId < 1) return null
    if (!Number.isFinite(setAt) || now - setAt > MAX_AGE_MS) return null
    return Math.floor(clientId)
  } catch {
    return null
  }
}

export function peekMailboxClientFilterHandoff(): MailboxClientFilterHandoff | null {
  if (typeof sessionStorage === 'undefined') return null
  const raw = sessionStorage.getItem(STORAGE_KEY)
  if (!raw) return null
  try {
    return JSON.parse(raw) as MailboxClientFilterHandoff
  } catch {
    return null
  }
}
