import { describe, expect, it } from 'vitest'
import { COMMUNICATION_INBOX_STATUS } from '../../app/utils/communication'
import { communicationSessionActions } from '../../app/utils/communication-session'

describe('ciclo administrativo da sessão WhatsApp', () => {
  it('expõe somente os três estados canônicos com badges em pt-BR', () => {
    expect(Object.keys(COMMUNICATION_INBOX_STATUS)).toEqual([
      'DISCONNECTED',
      'CONNECTING',
      'CONNECTED'
    ])
    expect(COMMUNICATION_INBOX_STATUS.DISCONNECTED.label).toBe('Desconectado')
    expect(COMMUNICATION_INBOX_STATUS.CONNECTING.label).toBe('Conectando')
    expect(COMMUNICATION_INBOX_STATUS.CONNECTED.label).toBe('Conectado')
  })

  it('aplica a matriz de ações conforme estado e credenciais', () => {
    expect(communicationSessionActions('DISCONNECTED', false)).toEqual(['connect'])
    expect(communicationSessionActions('DISCONNECTED', true)).toEqual(['connect', 'logout'])
    expect(communicationSessionActions('CONNECTING', false)).toEqual(['disconnect'])
    expect(communicationSessionActions('CONNECTING', true)).toEqual(['disconnect'])
    expect(communicationSessionActions('CONNECTED', true)).toEqual(['disconnect', 'logout'])
    expect(communicationSessionActions('CONNECTED', false)).toEqual(['disconnect'])
  })
})
