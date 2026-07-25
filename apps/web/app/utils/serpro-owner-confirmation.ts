/**
 * Confirmação reforçada do proprietário (OWNER_CONFIRMATION) para ações SERPRO globais.
 * Frase esperada espelha o backend: CONFIRMO-{ACTION}.
 */

export type SerproOwnerAction
  = | 'KILL_SWITCH_OFF'
    | 'KILL_SWITCH_SOLUTION_OFF'
    | 'CONTRACT_ACTIVATE'
    | 'CREDENTIAL_CUTOVER'

export type SerproApprovalPolicy = 'OWNER_CONFIRMATION' | 'DUAL_ROLE'

export function expectedOwnerConfirmationPhrase(action: string): string {
  return `CONFIRMO-${String(action || '').toUpperCase()}`
}

export function isOwnerConfirmationPolicy(policy?: string | null): boolean {
  return String(policy || '').toUpperCase() === 'OWNER_CONFIRMATION'
}

export function isDualRolePolicy(policy?: string | null): boolean {
  return String(policy || '').toUpperCase() === 'DUAL_ROLE'
}

/** Janela padrão: agora −5min … agora +1h (ISO). */
export function defaultChangeWindow(now: Date = new Date()): {
  change_window_start: string
  change_window_end: string
} {
  const start = new Date(now.getTime() - 5 * 60 * 1000)
  const end = new Date(now.getTime() + 60 * 60 * 1000)
  return {
    change_window_start: start.toISOString(),
    change_window_end: end.toISOString()
  }
}

export function validateOwnerConfirmationInput(input: {
  reason?: string | null
  confirmationPhrase?: string | null
  expectedPhrase: string
  password?: string | null
  requirePassword?: boolean
}): { ok: true } | { ok: false, message: string } {
  if (!String(input.reason || '').trim()) {
    return { ok: false, message: 'Informe o motivo auditável da operação.' }
  }
  if (String(input.confirmationPhrase || '').trim() !== input.expectedPhrase) {
    return { ok: false, message: `Digite a frase exata: ${input.expectedPhrase}` }
  }
  if (input.requirePassword !== false && !String(input.password || '').trim()) {
    return { ok: false, message: 'Reconfirme a senha da sessão (válida por 15 minutos).' }
  }
  return { ok: true }
}

/** Payload HTTP para desligar kill switch com confirmação OWNER. */
export function buildKillSwitchOffBody(input: {
  reason: string
  confirmationPhrase: string
  solution?: string
  window?: { change_window_start: string, change_window_end: string }
}): Record<string, unknown> {
  const window = input.window || defaultChangeWindow()
  return {
    active: false,
    reason: input.reason.trim(),
    confirmation_phrase: input.confirmationPhrase.trim(),
    change_window_start: window.change_window_start,
    change_window_end: window.change_window_end,
    ...(input.solution ? { solution: input.solution } : {})
  }
}
