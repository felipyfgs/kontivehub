import { beforeEach, describe, expect, it } from 'vitest'
import {
  consumeAuthReturn,
  saveAuthReturn
} from '../../app/utils/auth-return'

describe('retorno autenticado one-shot', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('persiste somente o path canônico e consome uma vez', () => {
    saveAuthReturn('/communication/conversations/89?unassigned=1#origem')

    expect(consumeAuthReturn()).toBe('/communication/conversations/89')
    expect(consumeAuthReturn()).toBeNull()
  })

  it('descarta destinos externos e protocol-relative', () => {
    saveAuthReturn('https://example.test/phishing')
    saveAuthReturn('//example.test/phishing')

    expect(consumeAuthReturn()).toBeNull()
  })
})
