import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = (path: string) => resolve(process.cwd(), path)
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

  it('identifica autenticação e onboarding sem a marca ou domínio legados', () => {
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

    expect(reset).toContain('client(\'/reset-password\'')
    expect(reset).toContain('if (token.value || email.value)')
    expect(reset).toContain('window.history.replaceState(null, \'\', \'/reset-password\')')
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

  it('integra o build produtivo às origens públicas KontiveHub', () => {
    const compose = read('../../docker-compose.prod.yml')
    const dockerfile = read('../../infra/docker/nginx/Dockerfile')
    const nginx = read('../../infra/docker/nginx/conf/prod.conf')

    expect(compose).toContain('NUXT_PUBLIC_API_BASE: https://api.kontivehub.com.br')
    expect(compose).toContain('NUXT_PUBLIC_REVERB_HOST: api.kontivehub.com.br')
    expect(dockerfile).toContain('ARG NUXT_PUBLIC_API_BASE=https://api.kontivehub.com.br')
    expect(nginx).toContain('server_name app.kontivehub.com.br portal.kontivehub.com.br')
    expect(nginx).toContain('server_name api.kontivehub.com.br')
    expect(nginx).not.toContain('inovaicontabil.com.br')
  })
})
