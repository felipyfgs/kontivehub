import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('inicialização do realtime de comunicação', () => {
  it('aceita oldValue indefinido no primeiro disparo imediato do watcher', () => {
    const plugin = readFileSync(
      resolve(process.cwd(), 'app/plugins/communication-realtime.client.ts'),
      'utf8'
    )

    expect(plugin).toContain('previous ?? ([false, null] as const)')
  })

  it('invalida start em voo e desconecta pusher no teardown', () => {
    const plugin = readFileSync(
      resolve(process.cwd(), 'app/plugins/communication-realtime.client.ts'),
      'utf8'
    )

    expect(plugin).toContain('pusher?.disconnect()')
    expect(plugin).toContain('startRequest = null')
    expect(plugin).toContain('if ((startRequest || echo) && (wasAllowed !== allowed || previousTenantId !== tenantId)) teardown()')
  })
})
