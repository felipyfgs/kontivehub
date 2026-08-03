import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { consumeResetPasswordCredentials } from '../../app/utils/reset-password'

const webRoot = fileURLToPath(new URL('../..', import.meta.url))
const root = (path: string) => resolve(webRoot, path)
const read = (path: string) => readFileSync(root(path), 'utf8')
describe('identidade pública KontiveHub', () => {
  it('usa a marca canônica nos metadados globais e no manifesto PWA', () => {
    const app = read('app/app.vue')
    const nuxt = read('nuxt.config.ts')

    expect(app).toContain('const title = \'KontiveHub\'')
    expect(app).toContain('content: \'KontiveHub\'')
    expect(app).toContain('Gestão fiscal, documentos e trabalho para escritórios contábeis')
    expect(nuxt).toContain('name: \'KontiveHub\'')
    expect(nuxt).toContain('short_name: \'KontiveHub\'')
    expect(nuxt).not.toContain('name: \'NFS-e ADN\'')
  })

  it('identifica autenticação e onboarding somente com a marca atual', () => {
    const sources = [
      'app/layouts/auth.vue',
      'app/pages/login.vue',
      'app/pages/activate.vue',
      'app/pages/first-access.vue',
      'app/pages/onboarding.vue',
      'app/pages/reset-password.vue'
    ].map(read)

    for (const source of sources) {
      expect(source).not.toContain('Fiscal Hub')
      expect(source).not.toContain('inovaicontabil.com.br')
      expect(source).not.toContain('· NFS-e ADN')
    }

    expect(sources.join('\n')).toContain('KontiveHub')
    expect(read('app/pages/onboarding.vue')).toContain('Ex.: Contabilidade Horizonte')
  })

  it('mantém o callback de reset no frontend canônico e remove o token da URL', () => {
    const reset = read('app/pages/reset-password.vue')
    const calls: Array<Parameters<History['replaceState']>> = []
    const historyApi = {
      replaceState: (...args: Parameters<History['replaceState']>) => {
        calls.push(args)
      }
    }

    expect(reset).toContain('client(\'/reset-password\'')
    expect(reset).toContain('consumeResetPasswordCredentials()')
    expect(reset).toContain('if (!validLink.value) return')
    expect(consumeResetPasswordCredentials({
      pathname: '/reset-password',
      hash: '#token=abc%2B123&email=pessoa%2Bteste%40example.test'
    }, historyApi)).toEqual({
      token: 'abc+123',
      email: 'pessoa+teste@example.test'
    })
    expect(calls).toEqual([[null, '', '/reset-password']])

    calls.length = 0
    expect(consumeResetPasswordCredentials({
      pathname: '/reset-password',
      hash: '#token=ausente-email'
    }, historyApi)).toBeNull()
    expect(calls).toEqual([[null, '', '/reset-password']])

    calls.length = 0
    expect(consumeResetPasswordCredentials({
      pathname: '/reset-password',
      hash: ''
    }, historyApi)).toBeNull()
    expect(calls).toEqual([])
    expect(reset).toContain('Redefinir senha · KontiveHub')
    expect(reset).toContain('token: token.value')
    expect(reset).toContain('email: email.value')
  })

  it('mantém uma única SPA sem ramificação por hostname', () => {
    const appSources = [
      read('app/app.vue'),
      read('app/layouts/default.vue'),
      read('app/layouts/auth.vue')
    ].join('\n')

    expect(appSources).not.toMatch(/window\.location\.hostname|location\.host/)
  })

  it('mantém o build público configurável por ambiente', () => {
    const nuxt = read('nuxt.config.ts')
    const envExample = read('.env.example')

    expect(nuxt).toContain('process.env.NUXT_PUBLIC_API_BASE || \'\'')
    expect(nuxt).toContain('process.env.NUXT_PUBLIC_REVERB_HOST || \'\'')
    expect(envExample).toContain('produção usa https://api.kontivehub.com.br')
    expect(`${nuxt}\n${envExample}`).not.toContain('inovaicontabil.com.br')
  })
})
