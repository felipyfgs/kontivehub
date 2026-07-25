import { describe, expect, it } from 'vitest'
import { isAuthPublicPath } from '../../app/utils/auth-public'

describe('auth-public', () => {
  it('mantém o callback de reset público sem vazar a query para um redirect', () => {
    expect(isAuthPublicPath('/reset-password')).toBe(true)
    expect(isAuthPublicPath('/reset-password/')).toBe(true)
  })

  it('não classifica páginas autenticadas como públicas', () => {
    expect(isAuthPublicPath('/')).toBe(false)
    expect(isAuthPublicPath('/clients')).toBe(false)
  })
})
